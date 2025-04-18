<?php
declare(strict_types=1);

namespace Raxos\MessageBus\Contract;

use Exception;
use Raxos\Terminal\Printer;

/**
 * Interface HandlerInterface
 *
 * @author Bas Milius <bas@mili.us>
 * @package Raxos\MessageBus\Contract
 * @since 1.8.0
 */
interface HandlerInterface
{

    /**
     * Handles the given message.
     *
     * @param MessageInterface $message
     * @param Printer $printer
     *
     * @return void
     * @throws Exception
     * @author Bas Milius <bas@mili.us>
     * @since 1.8.0
     */
    public function handle(MessageInterface $message, Printer $printer): void;

}
