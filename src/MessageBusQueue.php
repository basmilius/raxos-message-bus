<?php
declare(strict_types=1);

namespace Raxos\MessageBus;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Raxos\Contract\MessageBus\HandlerInterface;
use Raxos\Contract\MessageBus\MessageBusQueueInterface;
use Raxos\Contract\MessageBus\MessageInterface;
use Raxos\Error\InvalidArgumentException;
use Raxos\Foundation\Util\Singleton;
use Raxos\MessageBus\Attribute\Handler;
use Raxos\MessageBus\Enum\MessagePriority;
use Raxos\MessageBus\Error\MessageBusConsumeException;
use Raxos\MessageBus\Error\MessageBusMissingHandlerException;
use Raxos\MessageBus\Error\MessageBusPublishException;
use ReflectionClass;
use RuntimeException;
use Throwable;
use function in_array;
use function is_a;
use function is_bool;
use function is_int;
use function preg_match;
use function serialize;
use function unserialize;

/**
 * Class MessageBusQueue
 *
 * Dispatches registered message classes and settles deliveries under an optional retry policy.
 *
 * @author Bas Milius <bas@mili.us>
 * @package Raxos\MessageBus
 * @since 1.8.0
 */
final readonly class MessageBusQueue implements MessageBusQueueInterface
{
    /**
     * MessageBusQueue constructor.
     *
     * @param MessageBus $messageBus
     * @param string $name
     * @param AMQPChannel $channel
     * @param int $maxMessages
     * @param class-string[] $allowedClasses
     * @param QueuePolicy|null $policy
     *
     * @author Bas Milius <bas@mili.us>
     * @since 1.8.0
     */
    public function __construct(
        public MessageBus $messageBus,
        public string $name,
        private AMQPChannel $channel,
        private int $maxMessages = 25,
        private array $allowedClasses = [],
        private ?QueuePolicy $policy = null
    )
    {
        if ($maxMessages < 1) {
            throw new InvalidArgumentException('The maximum message count must be positive.');
        }

        if ($policy !== null) {
            $this->declarePolicyQueues($policy);
        }
    }

    /**
     * {@inheritdoc}
     * @author Bas Milius <bas@mili.us>
     * @since 1.8.0
     */
    public function close(): void
    {
        $this->messageBus->removeQueue($this);
        $this->channel->close();
    }

    /**
     * {@inheritdoc}
     * @author Bas Milius <bas@mili.us>
     * @since 1.8.0
     */
    public function consume(callable $callback): void
    {
        $counter = 0;

        $consumer = function (AMQPMessage $delivery) use ($callback, &$counter): void {
            try {
                $this->consumeDelivery($delivery, $callback);
            } finally {
                if (++$counter >= $this->maxMessages) {
                    $this->channel->basic_cancel($delivery->getConsumerTag());
                }
            }
        };

        try {
            $this->channel->basic_qos(
                prefetch_size: 0,
                prefetch_count: $this->policy?->prefetch ?? 1,
                a_global: false
            );

            $this->channel->basic_consume(
                queue: $this->name,
                callback: $consumer
            );

            $this->channel->consume();
        } catch (Throwable $err) {
            throw new MessageBusConsumeException($err);
        }
    }

    /**
     * {@inheritdoc}
     * @author Bas Milius <bas@mili.us>
     * @since 1.8.0
     */
    public function publish(
        MessageInterface $message,
        MessagePriority $priority = MessagePriority::NORMAL
    ): void
    {
        try {
            $message = new AMQPMessage(serialize($message), [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'priority' => $priority->value
            ]);

            if ($this->policy !== null) {
                $this->publishConfirmed($message, $this->name);
            } else {
                $this->channel->basic_publish($message, routing_key: $this->name);
            }
        } catch (Throwable $err) {
            throw new MessageBusPublishException($err);
        }
    }

    /**
     * Declares durable retry and dead queues after enabling publisher confirmations.
     *
     * @param QueuePolicy $policy
     *
     * @return void
     * @throws Throwable
     * @author Bas Milius <bas@mili.us>
     * @since 3.3.0
     */
    private function declarePolicyQueues(QueuePolicy $policy): void
    {
        $this->channel->confirm_select();
        $this->channel->queue_declare($this->name . '.dead', false, true, false, false);

        foreach ($policy->allowedDelays as $delay) {
            if ($delay === 0) {
                continue;
            }

            $arguments = new AMQPTable([
                'x-queue-type' => 'quorum',
                'x-message-ttl' => $delay * 1000,
                'x-dead-letter-exchange' => '',
                'x-dead-letter-routing-key' => $this->name,
                'x-dead-letter-strategy' => 'at-least-once',
                'x-overflow' => 'reject-publish'
            ]);

            $this->channel->queue_declare(
                $this->name . '.retry.' . $delay,
                false,
                true,
                false,
                false,
                false,
                $arguments
            );
        }
    }

    /**
     * Rejects malformed deliveries before dispatch and settles a handler result under the selected policy.
     *
     * @param AMQPMessage $delivery
     * @param callable(HandlerInterface, MessageInterface):(bool|DeliveryOutcome) $callback
     *
     * @return void
     * @throws Throwable
     * @author Bas Milius <bas@mili.us>
     * @since 3.3.0
     */
    private function consumeDelivery(
        AMQPMessage $delivery,
        callable $callback
    ): void
    {
        $message = $this->decodeMessage($delivery->getBody());

        if ($message === null) {
            $delivery->nack();

            return;
        }

        try {
            $attribute = (new ReflectionClass($message))->getAttributes(Handler::class)[0] ?? null;

            if ($attribute === null) {
                throw new MessageBusMissingHandlerException($message::class);
            }

            $handlerClass = $attribute->newInstance()->handlerClass;
            $handler = $this->policy?->handlerResolver !== null
                ? ($this->policy->handlerResolver)($handlerClass)
                : Singleton::get($handlerClass);
            $result = $callback($handler, $message);

            if (!$result instanceof DeliveryOutcome && !is_bool($result)) {
                throw new InvalidArgumentException('A consumer must return bool or DeliveryOutcome.');
            }
        } catch (Throwable $error) {
            if ($this->policy === null) {
                if (!$error instanceof MessageBusMissingHandlerException) {
                    $delivery->nack(requeue: true);
                }

                throw $error;
            }

            $result = DeliveryOutcome::retry();
        }

        $outcome = $result instanceof DeliveryOutcome
            ? $result
            : ($result ? DeliveryOutcome::ack() : DeliveryOutcome::retry());

        if ($this->policy !== null) {
            $this->settle($delivery, $outcome);
        } elseif ($outcome->action === 'ack') {
            $delivery->ack();
        } else {
            $delivery->nack(requeue: $outcome->action === 'retry');
        }
    }

    /**
     * Checks the top-level class before unserialization so unauthorized object hooks cannot run.
     *
     * @param string $body
     *
     * @return MessageInterface|null
     * @author Bas Milius <bas@mili.us>
     * @since 3.3.0
     */
    private function decodeMessage(string $body): ?MessageInterface
    {
        if (!preg_match('/^O:\d+:"([^"]+)":/', $body, $matches)) {
            return null;
        }

        $class = $matches[1];

        if (!in_array($class, $this->allowedClasses, true) || !is_a($class, MessageInterface::class, true)) {
            return null;
        }

        try {
            $message = @unserialize($body, ['allowed_classes' => $this->allowedClasses]);
        } catch (Throwable) {
            return null;
        }

        return $message instanceof MessageInterface ? $message : null;
    }

    /**
     * Confirms retry or dead-queue transfer before acknowledging the original delivery.
     *
     * @param AMQPMessage $message
     * @param DeliveryOutcome $outcome
     *
     * @return void
     * @throws Throwable
     * @author Bas Milius <bas@mili.us>
     * @since 3.3.0
     */
    private function settle(
        AMQPMessage $message,
        DeliveryOutcome $outcome
    ): void
    {
        if ($outcome->action === 'ack') {
            $message->ack();

            return;
        }
        $headers = $message->has('application_headers') ? $message->get('application_headers')->getNativeData() : [];
        $attempt = $headers['x-raxos-attempt'] ?? 1;

        if (!is_int($attempt) || $attempt < 1) {
            $attempt = $this->policy->maxAttempts;
        }
        $reject = $outcome->action === 'reject' || $attempt >= $this->policy->maxAttempts;
        $delay = $outcome->delay ?? $this->policy->retryDelay;

        if (!$reject && !in_array($delay, $this->policy->allowedDelays, true)) {
            throw new InvalidArgumentException('The retry delay is not allowed by this queue policy.');
        }
        $properties = $message->get_properties();
        unset($properties['expiration']);
        $headers['x-raxos-attempt'] = $reject ? $attempt : $attempt + 1;
        $properties['application_headers'] = new AMQPTable($headers);
        $properties['delivery_mode'] = AMQPMessage::DELIVERY_MODE_PERSISTENT;
        $route = match (true) {
            $reject => $this->name . '.dead',
            $delay === 0 => $this->name,
            default => $this->name . '.retry.' . $delay
        };
        $this->publishConfirmed(new AMQPMessage($message->getBody(), $properties), $route);
        $message->ack();
    }

    /**
     * Requires a broker confirmation and a routable mandatory publication before returning.
     *
     * @param AMQPMessage $message
     * @param string $route
     *
     * @return void
     * @throws Throwable
     * @author Bas Milius <bas@mili.us>
     * @since 3.3.0
     */
    private function publishConfirmed(
        AMQPMessage $message,
        string $route
    ): void
    {
        $acknowledged = false;
        $failed = false;
        $this->channel->set_ack_handler(static function () use (&$acknowledged): void {
            $acknowledged = true;
        });
        $this->channel->set_nack_handler(static function () use (&$failed): void {
            $failed = true;
        });
        $this->channel->set_return_listener(static function () use (&$failed): void {
            $failed = true;
        });

        try {
            $this->channel->basic_publish($message, routing_key: $route, mandatory: true);
            $this->channel->wait_for_pending_acks_returns($this->policy->confirmTimeout);

            if (!$acknowledged || $failed) {
                throw new RuntimeException('The broker did not confirm a routable publication.');
            }
        } finally {
            $this->channel->set_ack_handler(static fn() => null);
            $this->channel->set_nack_handler(static fn() => null);
            $this->channel->set_return_listener(static fn() => null);
        }
    }
}
