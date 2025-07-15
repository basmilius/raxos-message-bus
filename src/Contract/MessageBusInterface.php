<?php
declare(strict_types=1);

namespace Raxos\MessageBus\Contract;

use Raxos\MessageBus\Error\MessageBusException;

/**
 * Interface MessageBusInterface
 *
 * @author Bas Milius <bas@mili.us>
 * @package Raxos\MessageBus\Contract
 * @since 1.8.0
 */
interface MessageBusInterface
{

    /**
     * Close the connection.
     *
     * @return void
     * @throws MessageBusException
     * @author Bas Milius <bas@mili.us>
     * @since 1.8.0
     */
    public function close(): void;

    /**
     * Create a new queue.
     *
     * @param string $name
     * @param int $maxMessages
     *
     * @return MessageBusQueueInterface
     * @throws MessageBusException
     * @author Bas Milius <bas@mili.us>
     * @since 1.8.0
     */
    public function createQueue(string $name = 'task_queue', int $maxMessages = 25): MessageBusQueueInterface;

    /**
     * Remove a queue.
     *
     * @param MessageBusQueueInterface $queue
     *
     * @return void
     * @author Bas Milius <bas@mili.us>
     * @since 1.8.0
     */
    public function removeQueue(MessageBusQueueInterface $queue): void;

}
