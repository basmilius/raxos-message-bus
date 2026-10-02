<?php
declare(strict_types=1);

namespace RaxosTests\MessageBus;

use Raxos\Contract\MessageBus\{HandlerInterface, MessageInterface};
use Raxos\MessageBus\Attribute\Handler;
use Raxos\Terminal\Printer;
use RuntimeException;

#[Handler(InvitationHandler::class)]
final class Invitation implements MessageInterface
{
    public function __construct(public string $merchantId, public string $invitationId)
    {
    }

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
    public function handle(MessageInterface $message, Printer $printer): void
    {
    }
}

final class PoisonMessage
{
    public static int $executions = 0;

    public function __wakeup(): void
    {
        self::$executions++;
    }
}

final class MissingHandlerMessage implements MessageInterface
{
    public function __serialize(): array
    {
        return [];
    }
    public function __unserialize(array $data): void
    {
    }
}

#[Handler(InvitationHandler::class)]
final class ThrowingMessage implements MessageInterface
{
    public function __serialize(): array
    {
        return [];
    }
    public function __unserialize(array $data): void
    {
        throw new RuntimeException('Invalid payload');
    }
}
