<?php
declare(strict_types=1);

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Raxos\Contract\MessageBus\HandlerInterface;
use Raxos\MessageBus\Enum\MessagePriority;
use Raxos\MessageBus\Error\MessageBusConsumeException;
use Raxos\MessageBus\Error\MessageBusPublishException;
use Raxos\MessageBus\MessageBus;
use Raxos\MessageBus\MessageBusQueue;
use RaxosTests\MessageBus\Invitation;
use RaxosTests\MessageBus\MissingHandlerMessage;
use RaxosTests\MessageBus\PoisonMessage;
use RaxosTests\MessageBus\ThrowingMessage;

covers(MessageBusQueue::class);

it('removes a closed queue from the bus before shutting down', function (): void {
    $channel = $this->createMock(AMQPChannel::class);
    $connection = $this->createMock(AMQPStreamConnection::class);
    $connection->method('channel')->willReturn($channel);
    $channel->expects($this->once())->method('close');
    $connection->expects($this->once())->method('close');
    $bus = new MessageBus('unused', 5672, 'test', 'test', connection: $connection);
    $queue = $bus->createQueue();
    $queue->close();
    $bus->close();
});

it('rejects non-message objects before their hooks execute even when explicitly allowed', function (bool $allowed): void {
    PoisonMessage::$executions = 0;
    $channel = $this->createMock(AMQPChannel::class);
    $connection = $this->createMock(AMQPStreamConnection::class);
    $connection->method('channel')->willReturn($channel);
    $incoming = $this->getMockBuilder(AMQPMessage::class)->setConstructorArgs([serialize(new PoisonMessage())])->onlyMethods(['ack', 'nack'])->getMock();
    $incoming->expects($this->once())->method('nack')->with(false, false);
    $incoming->expects($this->never())->method('ack');
    $consumer = null;
    $channel->method('basic_consume')->willReturnCallback(static function (mixed ...$args) use (&$consumer): string {
        $consumer = $args[6];

        return 'test-consumer';
    });
    $channel->method('consume')->willReturnCallback(static function () use (&$consumer, $incoming): void {
        $consumer($incoming);
    });
    $bus = new MessageBus('unused', 5672, 'test', 'test', connection: $connection);
    $bus->createQueue(allowedClasses: $allowed ? [PoisonMessage::class] : [])->consume(static fn() => throw new RuntimeException('Must not dispatch.'));
    expect(PoisonMessage::$executions)->toBe(0);
})->with([false, true]);

it('rejects malformed and unauthorized payloads without invoking application handlers', function (string $body, array $allowed): void {
    $channel = $this->createMock(AMQPChannel::class);
    $connection = $this->createMock(AMQPStreamConnection::class);
    $connection->method('channel')->willReturn($channel);
    $incoming = $this->getMockBuilder(AMQPMessage::class)->setConstructorArgs([$body])->onlyMethods(['ack', 'nack'])->getMock();
    $incoming->expects($this->once())->method('nack')->with(false, false);
    $incoming->expects($this->never())->method('ack');
    $consumer = null;
    $channel->method('basic_consume')->willReturnCallback(static function (mixed ...$arguments) use (&$consumer): string {
        $consumer = $arguments[6];

        return 'unit';
    });
    $channel->method('consume')->willReturnCallback(static function () use (&$consumer, $incoming): void {
        $consumer($incoming);
    });
    $bus = new MessageBus('unused', 5672, 'test', 'test', connection: $connection);
    set_error_handler(static fn(int $severity, string $message): bool => $severity === E_WARNING && str_starts_with($message, 'unserialize():'));

    try {
        $bus->createQueue(allowedClasses: $allowed)->consume(static fn(): never => throw new LogicException('Must not dispatch rejected payloads.'));
    } finally {
        restore_error_handler();
    }
})->with([
    ['not serialized', []], ['N;', []], [serialize(new Invitation('merchant', 'invite')), []],
    ['O:31:"RaxosTests\\MessageBus\\Invitation":1:{', [Invitation::class]],
    [serialize(new ThrowingMessage()), [ThrowingMessage::class]]
]);

it('cancels consumption when the configured message count is reached', function (): void {
    $channel = $this->createMock(AMQPChannel::class);
    $connection = $this->createMock(AMQPStreamConnection::class);
    $connection->method('channel')->willReturn($channel);
    $incoming = $this->getMockBuilder(AMQPMessage::class)->setConstructorArgs([serialize(new Invitation('merchant', 'invite'))])->onlyMethods(['ack', 'nack', 'getConsumerTag'])->getMock();
    $incoming->method('getConsumerTag')->willReturn('unit-consumer');
    $incoming->expects($this->exactly(2))->method('ack');
    $incoming->expects($this->never())->method('nack');
    $channel->expects($this->once())->method('basic_qos')->with(0, 1, false);
    $channel->expects($this->once())->method('basic_cancel')->with('unit-consumer');
    $consumer = null;
    $channel->method('basic_consume')->willReturnCallback(static function (mixed ...$arguments) use (&$consumer): string {
        $consumer = $arguments[6];

        return 'unit-consumer';
    });
    $channel->method('consume')->willReturnCallback(static function () use (&$consumer, $incoming): void {
        $consumer($incoming);
        $consumer($incoming);
    });
    $bus = new MessageBus('unused', 5672, 'test', 'test', connection: $connection);
    $bus->createQueue(maxMessages: 2, allowedClasses: [Invitation::class])->consume(static fn(): bool => true);
});

it('reports registered messages without a handler attribute as a consumption error', function (): void {
    $channel = $this->createMock(AMQPChannel::class);
    $connection = $this->createMock(AMQPStreamConnection::class);
    $connection->method('channel')->willReturn($channel);
    $message = new AMQPMessage(serialize(new MissingHandlerMessage()));
    $consumer = null;
    $channel->method('basic_consume')->willReturnCallback(static function (mixed ...$arguments) use (&$consumer): string {
        $consumer = $arguments[6];

        return 'unit';
    });
    $channel->method('consume')->willReturnCallback(static function () use (&$consumer, $message): void {
        $consumer($message);
    });
    $bus = new MessageBus('unused', 5672, 'test', 'test', connection: $connection);
    expect(fn() => $bus->createQueue(allowedClasses: [MissingHandlerMessage::class])->consume(static fn(): bool => true))
        ->toThrow(MessageBusConsumeException::class);
});

it('publishes persistent messages with the chosen priority and routing key', function (MessagePriority $priority): void {
    $channel = $this->createMock(AMQPChannel::class);
    $connection = $this->createMock(AMQPStreamConnection::class);
    $connection->method('channel')->willReturn($channel);
    $channel->expects($this->once())->method('basic_publish')->willReturnCallback(static function (AMQPMessage $message, string $exchange, string $routingKey) use ($priority): void {
        expect($routingKey)->toBe('invitations')
            ->and($message->get('delivery_mode'))->toBe(AMQPMessage::DELIVERY_MODE_PERSISTENT)
            ->and($message->get('priority'))->toBe($priority->value)
            ->and(unserialize($message->getBody(), ['allowed_classes' => [Invitation::class]])->__serialize())->toBe(['merchant', 'invitation']);
    });
    $bus = new MessageBus('unused', 5672, 'test', 'test', connection: $connection);
    $bus->createQueue('invitations')->publish(new Invitation('merchant', 'invitation'), $priority);
})->with(MessagePriority::cases());

it('preserves the transport failure as the publication exception cause', function (): void {
    $failure = new RuntimeException('transport failed');
    $channel = $this->createMock(AMQPChannel::class);
    $connection = $this->createMock(AMQPStreamConnection::class);
    $connection->method('channel')->willReturn($channel);
    $channel->method('basic_publish')->willThrowException($failure);
    $bus = new MessageBus('unused', 5672, 'test', 'test', connection: $connection);

    try {
        $bus->createQueue()->publish(new Invitation('merchant', 'invitation'));
        $this->fail('Expected a publication failure.');
    } catch (MessageBusPublishException $exception) {
        expect($exception->getPrevious())->toBe($failure);
    }
});

it('dispatches explicitly registered messages with the consumer payload shape', function (): void {
    $channel = $this->createMock(AMQPChannel::class);
    $connection = $this->createMock(AMQPStreamConnection::class);
    $connection->method('channel')->willReturn($channel);
    $incoming = $this->getMockBuilder(AMQPMessage::class)->setConstructorArgs([serialize(new Invitation('merchant', 'invitation'))])->onlyMethods(['ack', 'nack'])->getMock();
    $incoming->expects($this->once())->method('ack');
    $incoming->expects($this->never())->method('nack');
    $consumer = null;
    $channel->method('basic_consume')->willReturnCallback(static function (mixed ...$args) use (&$consumer): string {
        $consumer = $args[6];

        return 'test-consumer';
    });
    $channel->method('consume')->willReturnCallback(static function () use (&$consumer, $incoming): void {
        $consumer($incoming);
    });
    $bus = new MessageBus('unused', 5672, 'test', 'test', connection: $connection);
    $bus->createQueue(allowedClasses: [Invitation::class])->consume(static function (HandlerInterface $handler, Invitation $message): bool {
        expect($message->merchantId)->toBe('merchant');
        expect($message->invitationId)->toBe('invitation');

        return true;
    });
});

it('requeues rejected and failing handlers without acknowledging a delivery', function (bool $throws): void {
    $channel = $this->createMock(AMQPChannel::class);
    $connection = $this->createMock(AMQPStreamConnection::class);
    $connection->method('channel')->willReturn($channel);
    $incoming = $this->getMockBuilder(AMQPMessage::class)->setConstructorArgs([serialize(new Invitation('merchant', 'invitation'))])->onlyMethods(['ack', 'nack'])->getMock();
    $incoming->expects($this->once())->method('nack')->with(true, false);
    $incoming->expects($this->never())->method('ack');
    $consumer = null;
    $channel->method('basic_consume')->willReturnCallback(static function (mixed ...$args) use (&$consumer): string {
        $consumer = $args[6];

        return 'test-consumer';
    });
    $channel->method('consume')->willReturnCallback(static function () use (&$consumer, $incoming): void {
        $consumer($incoming);
    });
    $bus = new MessageBus('unused', 5672, 'test', 'test', connection: $connection);
    $callback = static function (mixed $handler, Invitation $message) use ($throws): bool {
        if ($throws) {
            throw new RuntimeException('handler failure');
        }

        return false;
    };

    if ($throws) {
        expect(fn(): mixed => $bus->createQueue(allowedClasses: [Invitation::class])->consume($callback))->toThrow(MessageBusConsumeException::class);
    } else {
        $bus->createQueue(allowedClasses: [Invitation::class])->consume($callback);
    }
})->with([false, true]);
