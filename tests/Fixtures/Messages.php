<?php
declare(strict_types=1);

namespace RaxosTests\MessageBus;

use Raxos\Contract\MessageBus\HandlerInterface;
use Raxos\Contract\MessageBus\MessageInterface;
use Raxos\MessageBus\Attribute\Handler;
use Raxos\Terminal\Printer;

#[Handler(InvitationHandler::class)]
final class Invitation implements MessageInterface
{
    public function __construct(public string $merchantId, public string $invitationId) {}

    public function __serialize(): array
    {
        return [$this->merchantId, $this->invitationId];
    }

    public function __unserialize(array $data): void
    {
        [$this->merchantId, $this->invitationId] = $data;
    }
}

final readonly class InvitationHandler implements HandlerInterface
{
    public function handle(MessageInterface $message, Printer $printer): void {}
}

final class PoisonMessage
{
    public static int $executions = 0;

    public function __wakeup(): void
    {
        self::$executions++;
    }
}
