<?php
declare(strict_types=1);

namespace Error;

use Raxos\Contract\MessageBus\MessageBusExceptionInterface;
use Raxos\Error\Exception;
use Throwable;

/**
 * Class MessageBusConsumeException
 *
 * @author Bas Milius <bas@mili.us>
 * @package Raxos\MessageBus\Error
 * @since 2.0.0
 */
final class MessageBusConsumeException extends Exception implements MessageBusExceptionInterface
{

    /**
     * MessageBusConsumeException constructor.
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
            'message_bus_consume',
            'Failed to consume the message.',
            previous: $this->err
        );
    }

}
