<?php
declare(strict_types=1);

namespace Raxos\MessageBus\Error;

use Raxos\Contract\MessageBus\MessageBusExceptionInterface;
use Raxos\Error\Exception;
use Throwable;

/**
 * Class MessageBusPublishException
 *
 * @author Bas Milius <bas@mili.us>
 * @package Raxos\MessageBus\Error
 * @since 2.0.0
 */
final class MessageBusPublishException extends Exception implements MessageBusExceptionInterface
{

    /**
     * MessageBusPublishException constructor.
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
            'message_bus_publish',
            'Failed to publish the message.',
            previous: $this->err
        );
    }

}
