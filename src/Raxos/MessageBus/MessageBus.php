<?php
declare(strict_types=1);

namespace Raxos\MessageBus;

use Exception;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Wire\AMQPTable;
use Raxos\Foundation\Collection\ArrayList;
use Raxos\Foundation\Contract\ArrayListInterface;
use Raxos\MessageBus\Error\MessageBusException;
use SensitiveParameter;

/**
 * Class MessageBus
 *
 * @author Bas Milius <bas@mili.us>
 * @package Raxos\MessageBus
 * @since 1.8.0
 */
final readonly class MessageBus
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
     * @throws MessageBusException
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
            throw MessageBusException::connection($err);
        }
    }

    /**
     * Closes the connection.
     *
     * @return void
     * @throws MessageBusException
     * @author Bas Milius <bas@mili.us>
     * @since 1.8.0
     */
    public function close(): void
    {
        try {
            $this->channels->each(static fn(MessageBusQueue $queue) => $queue->close());
            $this->connection->close();
        } catch (Exception $err) {
            throw MessageBusException::connection($err);
        }
    }

    /**
     * Creates a new queue with the given name.
     *
     * @param string $name
     * @param int $maxMessages
     *
     * @return MessageBusQueue
     * @throws MessageBusException
     * @author Bas Milius <bas@mili.us>
     * @since 1.8.0
     */
    public function createQueue(string $name = 'task_queue', int $maxMessages = 25): MessageBusQueue
    {
        try {
            $channel = $this->connection->channel();
            $channel->queue_declare($name, false, true, false, false, false, new AMQPTable(['x-max-priority' => 5]));

            $queue = new MessageBusQueue($this, $name, $channel, $maxMessages);
            $this->channels->append($queue);

            return $queue;
        } catch (AMQPTimeoutException $err) {
            throw MessageBusException::timeout($err);
        }
    }

    /**
     * Remove the channel reference.
     *
     * @param MessageBusQueue $queue
     *
     * @return void
     * @author Bas Milius <bas@mili.us>
     * @since 1.8.0
     * @internal
     * @private
     */
    public function removeQueue(MessageBusQueue $queue): void
    {
        $offset = $this->channels->search($queue);
        $this->channels->splice($offset, 1);
    }

}
