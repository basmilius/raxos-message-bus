<a href="https://bas.dev">
    <img src="https://bmcdn.nl/assets/branding/logo.svg" alt="Bas Milius" height="48" />
</a>

---

# Raxos Message Bus

Publish and consume serialized PHP messages through RabbitMQ queues.

[Documentation](https://raxos.dev/message-bus/) | [Packagist](https://packagist.org/packages/raxos/message-bus) | [Raxos](https://github.com/basmilius/raxos)

- Durable queues, persistent delivery and five message-priority levels.
- Explicit accepted-class lists for deserialization.
- Message-to-handler attributes, acknowledgement callbacks and worker limits.

## Installation

Requires PHP 8.5 or later. Composer checks the remaining package and extension dependencies declared in [composer.json](composer.json).

```sh
composer require "raxos/message-bus:^3.2"
```

## Usage

```php
<?php
declare(strict_types=1);

use Raxos\Contract\MessageBus\HandlerInterface;
use Raxos\Contract\MessageBus\MessageBusInterface;
use Raxos\Contract\MessageBus\MessageInterface;
use Raxos\MessageBus\Attribute\Handler;
use Raxos\MessageBus\Enum\MessagePriority;
use Raxos\Terminal\Printer;

require __DIR__ . '/vendor/autoload.php';

#[Handler(GreetingHandler::class)]
final class Greeting implements MessageInterface
{
    public function __construct(public string $text) {}

    public function __serialize(): array
    {
        return ['text' => $this->text];
    }

    public function __unserialize(array $data): void
    {
        $this->text = (string)$data['text'];
    }
}

final readonly class GreetingHandler implements HandlerInterface
{
    public function handle(MessageInterface $message, Printer $printer): void
    {
        if ($message instanceof Greeting) {
            $printer->out($message->text);
        }
    }
}

function publishGreeting(MessageBusInterface $bus): void
{
    $queue = $bus->createQueue('greetings', allowedClasses: [Greeting::class]);

    $queue->publish(new Greeting('Hello'), MessagePriority::HIGH);
    $queue->close();
}
```

Create a connected `MessageBus` with your RabbitMQ host, port, username and password, then pass it to `publishGreeting()`. Consumers must configure the same allowed classes, including nested value objects; the default empty list rejects serialized deliveries. A consumer callback receives the handler and message: return `true` to acknowledge, or `false` to requeue. Handler failures also requeue; malformed or unregistered payloads are rejected without requeueing. Close the bus when the process finishes.

## Documentation

- [Messages and handlers](https://raxos.dev/message-bus/messages-and-handlers)
- [Publishing and consuming](https://raxos.dev/message-bus/publishing-and-consuming)

## Testing

Run this library's Pest suite from the Raxos workspace:

```sh
git clone --recurse-submodules https://github.com/basmilius/raxos.git
cd raxos
composer install
vendor/bin/pest --testsuite=message-bus
```

See [Testing Raxos](https://github.com/basmilius/raxos/blob/main/TESTING.md) for PHP extensions, integration services and coverage commands. The library's [Tests workflow](.github/workflows/tests.yml) also runs in GitHub Actions.

## License

[MIT](LICENSE). Copyright (c) 2017 - present Bas Milius.
