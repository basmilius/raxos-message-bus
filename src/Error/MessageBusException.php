<?php
declare(strict_types=1);

namespace Raxos\MessageBus\Error;

use Exception;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use Raxos\Foundation\Error\{ExceptionId, RaxosException};

/**
 * Class MessageBusException
 *
 * @author Bas Milius <bas@mili.us>
 * @package Raxos\MessageBus\Error
 * @since 1.8.0
 */
final class MessageBusException extends RaxosException
{

    /**
     * Returns the exception for when a connection exception occurs.
     *
     * @param Exception $err
     *
     * @return self
     * @author Bas Milius <bas@mili.us>
     * @since 1.8.0
     */
    public static function connection(Exception $err): self
    {
        return new self(
            ExceptionId::guess(),
            'message_bus_connection',
            'Failed to connect to the message bus.',
            $err
        );
    }

    /**
     * Returns the exception for when consuming messages fails.
     *
     * @param Exception $err
     *
     * @return self
     * @author Bas Milius <bas@mili.us>
     * @since 1.8.0
     */
    public static function consume(Exception $err): self
    {
        return new self(
            ExceptionId::guess(),
            'message_bus_consume',
            'Failed to consume a message.',
            $err
        );
    }

    /**
     * Returns the exception for when a message handler is missing.
     *
     * @param string $messageClass
     *
     * @return self
     * @author Bas Milius <bas@mili.us>
     * @since 1.8.0
     */
    public static function missingHandler(string $messageClass): self
    {
        return new self(
            ExceptionId::guess(),
            'message_bus_missing_handler',
            "No handler found for message: {$messageClass}"
        );
    }

    /**
     * Returns the exception for when publishing messages fails.
     *
     * @param Exception $err
     *
     * @return self
     * @author Bas Milius <bas@mili.us>
     * @since 1.8.0
     */
    public static function publish(Exception $err): self
    {
        return new self(
            ExceptionId::guess(),
            'message_bus_publish',
            'Failed to publish a message.',
            $err
        );
    }

    /**
     * Returns the exception for when a timeout occurs.
     *
     * @param AMQPTimeoutException $err
     *
     * @return self
     * @author Bas Milius <bas@mili.us>
     * @since 1.8.0
     */
    public static function timeout(AMQPTimeoutException $err): self
    {
        return new self(
            ExceptionId::guess(),
            'message_bus_timeout',
            'Failed to consume a message.',
            $err
        );
    }

}
