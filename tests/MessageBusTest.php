<?php
declare(strict_types=1);

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Wire\AMQPTable;
use Raxos\MessageBus\Error\{MessageBusConnectionException, MessageBusTimeoutException};
use Raxos\MessageBus\MessageBus;

covers(MessageBus::class);

it('declares durable priority queues and closes all owned resources', function (): void {
    $first = $this->createMock(AMQPChannel::class);
    $second = $this->createMock(AMQPChannel::class);
    $connection = $this->createMock(AMQPStreamConnection::class);
    $connection->expects($this->exactly(2))->method('channel')->willReturnOnConsecutiveCalls($first, $second);
    foreach ([[$first, 'first'], [$second, 'second']] as [$channel, $name]) {
        $channel->expects($this->once())->method('queue_declare')->with($name, false, true, false, false, false, $this->callback(static fn (AMQPTable $table): bool => $table->getNativeData() === ['x-max-priority' => 5]));
        $channel->expects($this->once())->method('close');
    }
    $connection->expects($this->once())->method('close');
    $bus = new MessageBus('unused', 5672, 'test', 'test', connection: $connection);
    $bus->createQueue('first');
    $bus->createQueue('second');
    $bus->close();
});

it('wraps channel timeouts and retains the original transport exception', function (): void {
    $connection = $this->createMock(AMQPStreamConnection::class);
    $error = new AMQPTimeoutException('timeout');
    $connection->method('channel')->willThrowException($error);
    $bus = new MessageBus('unused', 5672, 'test', 'test', connection: $connection);
    try {
        $bus->createQueue();
        test()->fail('Expected a queue timeout.');
    } catch (MessageBusTimeoutException $actual) {
        expect($actual->getPrevious())->toBe($error);
    }
});

it('wraps connection close failures without losing the cause', function (): void {
    $connection = $this->createMock(AMQPStreamConnection::class);
    $error = new RuntimeException('close failed');
    $connection->method('close')->willThrowException($error);
    $bus = new MessageBus('unused', 5672, 'test', 'test', connection: $connection);
    try {
        $bus->close();
        test()->fail('Expected a close failure.');
    } catch (MessageBusConnectionException $actual) {
        expect($actual->getPrevious())->toBe($error);
    }
});
