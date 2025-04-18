<?php
declare(strict_types=1);

namespace Raxos\MessageBus\Enum;

/**
 * Enum MessagePriority
 *
 * @author Bas Milius <bas@mili.us>
 * @package Raxos\MessageBus\Enum
 * @since 1.8.0
 */
enum MessagePriority: int
{
    case VERY_LOW = 1;
    case LOW = 2;
    case NORMAL = 3;
    case HIGH = 4;
    case VERY_HIGH = 5;
}
