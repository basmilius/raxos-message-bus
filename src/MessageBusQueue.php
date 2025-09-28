<?php
declare(strict_types=1);

namespace Raxos\MessageBus;

use Error\MessageBusConsumeException;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Raxos\Contract\MessageBus\{MessageBusQueueInterface, MessageInterface};
use Raxos\Foundation\Util\Singleton;
use Raxos\MessageBus\Attribute\Handler;
use Raxos\MessageBus\Enum\MessagePriority;
use Raxos\MessageBus\Error\{MessageBusMissingHandlerException, MessageBusPublishException};
use ReflectionClass;
use Throwable;
use function serialize;
use function unserialize;

/**
 * Class MessageBusQueue
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
     *
     * @author Bas Milius <bas@mili.us>
     * @since 1.8.0
     */
    public function __construct(
        public MessageBus $messageBus,
        public string $name,
        private AMQPChannel $channel,
        private int $maxMessages = 25
    ) {}

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

        $consumer = function (AMQPMessage $msg) use ($callback, &$counter): void {
            $body = $msg->getBody();
            $message = unserialize($body);

            $classRef = new ReflectionClass($message);
            $handlerAttribute = $classRef->getAttributes(Handler::class)[0] ?? null;

            if ($handlerAttribute === null) {
                throw new MessageBusMissingHandlerException($message::class);
            }

            $handlerAttribute = $handlerAttribute->newInstance();
            $handler = Singleton::get($handlerAttribute->handlerClass);

            if ($callback($handler, $message)) {
                $msg->ack();
            } else {
                $msg->nack(requeue: true);
            }

            $counter++;

            if ($counter >= $this->maxMessages) {
                exit(1);
            }
        };

        try {
            $this->channel->basic_qos(
                prefetch_size: 0,
                prefetch_count: 1,
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
    public function publish(MessageInterface $message, MessagePriority $priority = MessagePriority::NORMAL): void
    {
        try {
            $message = new AMQPMessage(serialize($message), [
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'priority' => $priority->value
            ]);

            $this->channel->basic_publish($message, routing_key: $this->name);
        } catch (Throwable $err) {
            throw new MessageBusPublishException($err);
        }
    }

}
