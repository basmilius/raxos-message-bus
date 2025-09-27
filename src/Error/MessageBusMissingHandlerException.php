<?php
declare(strict_types=1);

namespace Raxos\MessageBus\Error;

use Raxos\Contract\MessageBus\MessageBusExceptionInterface;
use Raxos\Error\Exception;

/**
 * Class MessageBusMissingHandlerException
 *
 * @author Bas Milius <bas@mili.us>
 * @package Raxos\MessageBus\Error
 * @since 2.0.0
 */
final class MessageBusMissingHandlerException extends Exception implements MessageBusExceptionInterface
{

    /**
     * MessageBusMissingHandlerException constructor.
     *
     * @param string $messageClass
     *
     * @author Bas Milius <bas@mili.us>
     * @since 2.0.0
     */
    public function __construct(
        private readonly string $messageClass
    )
    {
        parent::__construct(
            'message_bus_missing_handler',
            "No message handler defined for message {$this->messageClass}.",
        );
    }

}
