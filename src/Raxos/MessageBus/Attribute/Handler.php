<?php
declare(strict_types=1);

namespace Raxos\MessageBus\Attribute;

use Attribute;
use Raxos\MessageBus\Contract\HandlerInterface;

/**
 * Class Handler
 *
 * @author Bas Milius <bas@mili.us>
 * @package Raxos\MessageBus\Attribute
 * @since 1.8.0
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Handler
{

    /**
     * Handler constructor.
     *
     * @param class-string<HandlerInterface> $handlerClass
     *
     * @author Bas Milius <bas@mili.us>
     * @since 1.8.0
     */
    public function __construct(
        public string $handlerClass
    ) {}

}
