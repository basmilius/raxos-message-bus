<?php
declare(strict_types=1);

namespace Raxos\MessageBus;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Wire\AMQPTable;
use Raxos\Collection\ArrayList;
use Raxos\Contract\MessageBus\MessageBusExceptionInterface;
use Raxos\Contract\MessageBus\MessageBusInterface;
use Raxos\Contract\MessageBus\MessageBusQueueInterface;
use Raxos\MessageBus\Error\MessageBusConnectionException;
use Raxos\MessageBus\Error\MessageBusTimeoutException;
use SensitiveParameter;
use Throwable;

/**
 * Class MessageBus
 *
 * @author Bas Milius <bas@mili.us>
 * @package Raxos\MessageBus
 * @since 1.8.0
 */
final readonly class MessageBus implements MessageBusInterface
{

    /**
     * Retains the connection used by this object for its entire lifetime.
     *
     * @var AMQPStreamConnection
     * @author Bas Milius <bas@mili.us>
     * @since 1.8.0
     */
    private AMQPStreamConnection $connection;

    /**
     * Tracks open queues so their channels can be closed with the bus.
     *
     * @var ArrayList<int, MessageBusQueueInterface>
     * @author Bas Milius <bas@mili.us>
     * @since 3.2.0
     */
    private ArrayList $channels;

    /**
     * MessageBus constructor.
     *
     * @param string $host
     * @param int $port
     * @param string $username
     * @param string $password
     * @param string $vhost
     * @param AMQPStreamConnection|null $connection
     *
     * @throws MessageBusExceptionInterface
     * @author Bas Milius <bas@mili.us>
     * @since 1.8.0
     */
    public function __construct(
        #[SensitiveParameter] string $host,
        #[SensitiveParameter] int $port,
        #[SensitiveParameter] string $username,
        #[SensitiveParameter] string $password,
        string $vhost = '/',
        ?AMQPStreamConnection $connection = null
    )
    {
        $this->channels = new ArrayList();

        try {
            $this->connection = $connection ?? new AMQPStreamConnection($host, $port, $username, $password, $vhost);
        } catch (Throwable $err) {
            throw new MessageBusConnectionException($err);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @author Bas Milius <bas@mili.us>
     * @since 1.8.0
     */
    public function close(): void
    {
        try {
            $this->channels->each(static fn(MessageBusQueue $queue) => $queue->close());
            $this->connection->close();
        } catch (Throwable $err) {
            throw new MessageBusConnectionException($err);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @param class-string[] $allowedClasses
     * @param QueuePolicy|null $policy
     *
     * @author Bas Milius <bas@mili.us>
     * @since 1.8.0
     */
    public function createQueue(
        string $name = 'task_queue',
        int $maxMessages = 25,
        array $allowedClasses = [],
        ?QueuePolicy $policy = null
    ): MessageBusQueue
    {
        try {
            $channel = $this->connection->channel();
            $channel->queue_declare($name, false, true, false, false, false, new AMQPTable(['x-max-priority' => 5]));

            $queue = new MessageBusQueue($this, $name, $channel, $maxMessages, $allowedClasses, $policy);
            $this->channels->append($queue);

            return $queue;
        } catch (AMQPTimeoutException $err) {
            throw new MessageBusTimeoutException($err);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @author Bas Milius <bas@mili.us>
     * @since 1.8.0
     */
    public function removeQueue(MessageBusQueueInterface $queue): void
    {
        $offset = $this->channels->search($queue);

        if ($offset === null) {
            return;
        }

        unset($this->channels[$offset]);
    }

}
