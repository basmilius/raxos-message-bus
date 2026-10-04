<?php
declare(strict_types=1);

namespace Raxos\MessageBus;

use Raxos\Error\InvalidArgumentException;

/**
 * Class DeliveryOutcome
 *
 * Makes acknowledgement, delayed retry and final rejection explicit handler outcomes.
 *
 * @author Bas Milius <bas@mili.us>
 * @package Raxos\MessageBus
 * @since 3.3.0
 */
final readonly class DeliveryOutcome
{
    /**
     * Restricts construction to the supported acknowledgement, retry and rejection factories.
     *
     * @param string $action
     * @param int|null $delay
     * @author Bas Milius <bas@mili.us>
     * @since 3.3.0
     */
    private function __construct(
        public string $action,
        public ?int $delay = null
    )
    {
    }

    /**
     * Acknowledges successful processing without transferring the delivery to another queue.
     *
     * @return self
     * @author Bas Milius <bas@mili.us>
     * @since 3.3.0
     */
    public static function ack(): self
    {
        return new self('ack');
    }

    /**
     * Requests another attempt; a null delay uses the queue policy's default delay.
     *
     * @param int|null $delay
     * @return self
     * @throws InvalidArgumentException
     * @author Bas Milius <bas@mili.us>
     * @since 3.3.0
     */
    public static function retry(?int $delay = null): self
    {
        if ($delay !== null && $delay < 0) {
            throw new InvalidArgumentException('Retry delay cannot be negative.');
        }

        return new self('retry', $delay);
    }

    /**
     * Routes a policy-managed delivery to its dead queue without another processing attempt.
     *
     * @return self
     * @author Bas Milius <bas@mili.us>
     * @since 3.3.0
     */
    public static function reject(): self
    {
        return new self('reject');
    }
}
