<?php
declare(strict_types=1);

namespace Raxos\MessageBus;

use Closure;
use Raxos\Error\InvalidArgumentException;
use function in_array;
use function is_finite;
use function is_int;

/**
 * Class QueuePolicy
 *
 * Opts a queue into bounded retries and confirmed transfers to retry or dead queues.
 *
 * @author Bas Milius <bas@mili.us>
 * @package Raxos\MessageBus
 * @since 3.3.0
 */
final readonly class QueuePolicy
{

    /**
     * Bounds delivery attempts and prefetch. Retry delays and confirmation timeout are measured in seconds.
     *
     * @param int $maxAttempts
     * @param int $prefetch
     * @param int $retryDelay
     * @param array<array-key, mixed> $allowedDelays
     * @param float $confirmTimeout
     * @param Closure|null $handlerResolver
     *
     * @throws InvalidArgumentException
     * @author Bas Milius <bas@mili.us>
     * @since 3.3.0
     */
    public function __construct(
        public int $maxAttempts = 3,
        public int $prefetch = 1,
        public int $retryDelay = 1,
        public array $allowedDelays = [0, 1, 5, 30],
        public float $confirmTimeout = 5.0,
        public ?Closure $handlerResolver = null
    )
    {
        $validPrefetch = $prefetch >= 1 && $prefetch <= 65535;
        $validTimeout = is_finite($confirmTimeout) && $confirmTimeout > 0;
        $validDefaultDelay = in_array($retryDelay, $allowedDelays, true);

        if ($maxAttempts < 1 || !$validPrefetch || !$validTimeout || !$validDefaultDelay) {
            throw new InvalidArgumentException('Invalid queue delivery policy.');
        }

        foreach ($allowedDelays as $delay) {
            if (!is_int($delay) || $delay < 0 || $delay > 86400) {
                throw new InvalidArgumentException('Retry delays must be integer seconds between zero and one day.');
            }
        }
    }

}
