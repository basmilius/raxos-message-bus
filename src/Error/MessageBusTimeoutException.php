<?php
declare(strict_types=1);

namespace Raxos\MessageBus\Error;

use PhpAmqpLib\Exception\AMQPTimeoutException;
use Raxos\Contract\MessageBus\MessageBusExceptionInterface;
use Raxos\Error\Exception;

/**
 * Class MessageBusTimeoutException
 *
 * @author Bas Milius <bas@mili.us>
 * @package Raxos\MessageBus\Error
 * @since 2.0.0
 */
final class MessageBusTimeoutException extends Exception implements MessageBusExceptionInterface
{

    /**
     * MessageBusTimeoutException constructor.
     *
     * @param AMQPTimeoutException $err
     *
     * @author Bas Milius <bas@mili.us>
     * @since 2.0.0
     */
    public function __construct(
        private readonly AMQPTimeoutException $err
    )
    {
        parent::__construct(
            'message_bus_timeout',
            'Failed to consume the message due to a timeout.',
            previous: $this->err
        );
    }

}
