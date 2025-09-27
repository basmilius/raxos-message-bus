<?php
declare(strict_types=1);

namespace Raxos\MessageBus;

use Exception;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Wire\AMQPTable;
use Raxos\Collection\ArrayList;
use Raxos\Contract\Collection\ArrayListInterface;
use Raxos\Contract\MessageBus\{MessageBusExceptionInterface, MessageBusInterface, MessageBusQueueInterface};
use Raxos\MessageBus\Error\MessageBusConnectionException;
use Raxos\MessageBus\Error\MessageBusTimeoutException;
use SensitiveParameter;

/**
 * Class MessageBus
 *
 * @author Bas Milius <bas@mili.us>
 * @package Raxos\MessageBus
 * @since 1.8.0
 */
final readonly class MessageBus implements MessageBusInterface
{

    private AMQPStreamConnection $connection;
    private ArrayListInterface $channels;

    /**
     * MessageBus constructor.
     *
     * @param string $host
     * @param int $port
     * @param string $username
     * @param string $password
     *
     * @throws MessageBusExceptionInterface
     * @author Bas Milius <bas@mili.us>
     * @since 1.8.0
     */
    public function __construct(
        #[SensitiveParameter] string $host,
        #[SensitiveParameter] int $port,
        #[SensitiveParameter] string $username,
        #[SensitiveParameter] string $password
    )
    {
        $this->channels = new ArrayList();

        try {
            $this->connection = new AMQPStreamConnection($host, $port, $username, $password);
        } catch (Exception $err) {
            throw new MessageBusConnectionException($err);
        }
    }

    /**
     * {@inheritdoc}
     * @author Bas Milius <bas@mili.us>
     * @since 1.8.0
     */
    public function close(): void
    {
        try {
            $this->channels->each(static fn(MessageBusQueue $queue) => $queue->close());
            $this->connection->close();
        } catch (Exception $err) {
            throw new MessageBusConnectionException($err);
        }
    }

    /**
     * {@inheritdoc}
     * @author Bas Milius <bas@mili.us>
     * @since 1.8.0
     */
    public function createQueue(string $name = 'task_queue', int $maxMessages = 25): MessageBusQueueInterface
    {
        try {
            $channel = $this->connection->channel();
            $channel->queue_declare($name, false, true, false, false, false, new AMQPTable(['x-max-priority' => 5]));

            $queue = new MessageBusQueue($this, $name, $channel, $maxMessages);
            $this->channels->append($queue);

            return $queue;
        } catch (AMQPTimeoutException $err) {
            throw new MessageBusTimeoutException($err);
        }
    }

    /**
     * {@inheritdoc}
     * @author Bas Milius <bas@mili.us>
     * @since 1.8.0
     */
    public function removeQueue(MessageBusQueueInterface $queue): void
    {
        $offset = $this->channels->search($queue);
        $this->channels->splice($offset, 1);
    }

}
