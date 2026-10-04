<?php
declare(strict_types=1);

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use Raxos\Error\InvalidArgumentException;
use Raxos\MessageBus\DeliveryOutcome;
use Raxos\MessageBus\Error\MessageBusConsumeException;
use Raxos\MessageBus\Error\MessageBusPublishException;
use Raxos\MessageBus\MessageBus;
use Raxos\MessageBus\MessageBusQueue;
use Raxos\MessageBus\QueuePolicy;
use RaxosTests\MessageBus\Invitation;
use RaxosTests\MessageBus\InvitationHandler;

covers(QueuePolicy::class, DeliveryOutcome::class, MessageBusQueue::class);

it('rejects invalid attempts, prefetch, deadlines and unbounded retry delays', function (array $options): void {
    expect(fn() => new QueuePolicy(...$options))->toThrow(InvalidArgumentException::class);
})->with([[['maxAttempts' => 0]], [['prefetch' => 0]], [['confirmTimeout' => INF]], [['allowedDelays' => [-1]]], [['retryDelay' => 2]], [['allowedDelays' => [1, 86401]]]]);

it('fails a publication on a negative, missing or unroutable broker confirmation', function (string $result): void {
    $channel = test()->createMock(AMQPChannel::class);
    $connection = test()->createMock(AMQPStreamConnection::class);
    $connection->method('channel')->willReturn($channel);
    $ack = $nack = $returned = null;
    $channel->method('set_ack_handler')->willReturnCallback(static function ($fn) use (&$ack): void {
        $ack = $fn;
    });
    $channel->method('set_nack_handler')->willReturnCallback(static function ($fn) use (&$nack): void {
        $nack = $fn;
    });
    $channel->method('set_return_listener')->willReturnCallback(static function ($fn) use (&$returned): void {
        $returned = $fn;
    });
    $channel->method('wait_for_pending_acks_returns')->willReturnCallback(static function () use (&$ack, &$nack, &$returned, $result): void {
        if ($result === 'ack-return') {
            $returned();
            $ack();
        }

        if ($result === 'nack') {
            $nack();
        }
    });
    $bus = new MessageBus('unused', 5672, 'unit', 'unit', connection: $connection);
    $queue = $bus->createQueue(policy: new QueuePolicy());
    expect(fn() => $queue->publish(new Invitation('unit', 'message')))->toThrow(MessageBusPublishException::class);
})->with(['nack', 'missing', 'ack-return']);

function nativeQueueContext(int $maxMessages, QueuePolicy $policy): array
{
    if (!getenv('RAXOS_RABBITMQ_PORT')) {
        test()->markTestSkipped('Set RAXOS_RABBITMQ_PORT for disposable broker integration.');
    }
    $connection = new AMQPStreamConnection(getenv('RAXOS_RABBITMQ_HOST') ?: '127.0.0.1', (int)getenv('RAXOS_RABBITMQ_PORT'), getenv('RAXOS_RABBITMQ_USER') ?: 'guest', getenv('RAXOS_RABBITMQ_PASSWORD') ?: 'guest');
    $bus = new MessageBus('unused', 5672, 'unit', 'unit', connection: $connection);
    $name = 'raxos-unit-' . bin2hex(random_bytes(8));
    $queue = $bus->createQueue($name, $maxMessages, [Invitation::class], $policy);

    return [$bus, $queue, $connection->channel(), $name];
}

function cleanupNativeQueue(MessageBus $bus, AMQPChannel $admin, string $name, QueuePolicy $policy): void
{
    foreach ([$name, $name . '.dead', ...array_map(static fn(int $delay): string => $name . '.retry.' . $delay, array_filter($policy->allowedDelays))] as $queue) {
        $admin->queue_delete($queue);
    }
    $admin->close();
    $bus->close();
}

it('bounds repeated handler failures and confirms their transfer to a dead-letter queue', function (bool $throws): void {
    $handler = new InvitationHandler();
    $policy = new QueuePolicy(maxAttempts: 3, prefetch: 4, retryDelay: 0, handlerResolver: static fn(string $class): InvitationHandler => $handler);
    [$bus, $queue, $admin, $name] = nativeQueueContext(3, $policy);

    try {
        $queue->publish(new Invitation('unit', 'retry'));
        $seen = 0;
        $queue->consume(static function ($resolved, Invitation $message) use ($handler, $throws, &$seen): bool {
            expect($resolved)->toBe($handler)->and($message->invitationId)->toBe('retry');
            ++$seen;

            if ($throws) {
                throw new RuntimeException('retry');
            }

            return false;
        });
        $dead = $admin->basic_get($name . '.dead');
        expect($seen)->toBe(3)->and($admin->basic_get($name))->toBeNull()->and($dead)->not->toBeNull()
            ->and($dead->get('application_headers')->getNativeData()['x-raxos-attempt'])->toBe(3);
        $dead->ack();
    } finally {
        cleanupNativeQueue($bus, $admin, $name, $policy);
    }
})->with([false, true]);

it('routes delayed retries through RabbitMQ and preserves the message body', function (): void {
    $policy = new QueuePolicy(maxAttempts: 2, retryDelay: 1, allowedDelays: [0, 1]);
    [$bus, $queue, $admin, $name] = nativeQueueContext(2, $policy);

    try {
        $queue->publish(new Invitation('unit', 'delayed'));
        $seen = 0;
        $queue->consume(static function ($handler, Invitation $message) use (&$seen): DeliveryOutcome {
            expect($message->invitationId)->toBe('delayed');

            return ++$seen === 1 ? DeliveryOutcome::retry(1) : DeliveryOutcome::ack();
        });
        expect($seen)->toBe(2)->and($admin->basic_get($name))->toBeNull()->and($admin->basic_get($name . '.dead'))->toBeNull();
    } finally {
        cleanupNativeQueue($bus, $admin, $name, $policy);
    }
});

it('rejects explicitly and detects a missing publication route on a real broker', function (): void {
    $policy = new QueuePolicy(allowedDelays: [0], retryDelay: 0);
    [$bus, $queue, $admin, $name] = nativeQueueContext(1, $policy);

    try {
        $queue->publish(new Invitation('unit', 'reject'));
        $queue->consume(static fn(): DeliveryOutcome => DeliveryOutcome::reject());
        $dead = $admin->basic_get($name . '.dead');
        expect($dead)->not->toBeNull();
        $dead->ack();
        $admin->queue_delete($name);
        expect(fn() => $queue->publish(new Invitation('unit', 'missing')))->toThrow(MessageBusPublishException::class);
    } finally {
        cleanupNativeQueue($bus, $admin, $name, $policy);
    }
});

it('leaves an original delivery unacknowledged when a dead-queue transfer cannot be routed', function (): void {
    $policy = new QueuePolicy(allowedDelays: [0], retryDelay: 0);
    [$bus, $queue, $admin, $name] = nativeQueueContext(1, $policy);

    try {
        $queue->publish(new Invitation('unit', 'retained'));
        $admin->queue_delete($name . '.dead');
        expect(fn() => $queue->consume(static fn(): DeliveryOutcome => DeliveryOutcome::reject()))->toThrow(MessageBusConsumeException::class);
        $queue->close();
        $deadline = microtime(true) + 2;
        do {
            $redelivery = $admin->basic_get($name);

            if ($redelivery === null) {
                usleep(10000);
            }
        } while ($redelivery === null && microtime(true) < $deadline);
        expect($redelivery)->not->toBeNull()->and($redelivery->isRedelivered())->toBeTrue();
        $redelivery->ack();
    } finally {
        cleanupNativeQueue($bus, $admin, $name, $policy);
    }
});
