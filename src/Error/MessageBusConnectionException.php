<?php
declare(strict_types=1);

namespace Raxos\MessageBus\Error;

use Raxos\Contract\MessageBus\MessageBusExceptionInterface;
use Raxos\Error\Exception;
use Throwable;

/**
 * Class MessageBusConnectionException
 *
 * @author Bas Milius <bas@mili.us>
 * @package Raxos\MessageBus\Error
 * @since 2.0.0
 */
final class MessageBusConnectionException extends Exception implements MessageBusExceptionInterface
{

    /**
     * MessageBusConnectionException constructor.
     *
     * @param Throwable $err
     *
     * @author Bas Milius <bas@mili.us>
     * @since 2.0.0
     */
    public function __construct(
        private readonly Throwable $err
    )
    {
        parent::__construct(
            'message_bus_connection',
            'Failed to connect to the message bus server.',
            previous: $this->err
        );
    }

}
