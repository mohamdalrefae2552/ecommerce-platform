<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Shared\Messaging\Message;
use App\Shared\Messaging\MessageHandler;
use App\Shared\Messaging\MessagePublisher;
use App\Shared\Messaging\PublishFailed;
use App\Shared\Messaging\QueueConfig;
use Illuminate\Support\Str;

/**
 * In-memory broker for feature tests (bound by Tests\TestCase).
 *
 * - Records what was published.
 * - `deliver = true` hands each message at once to the handler of every
 *   queue bound to its type, like RabbitMQ and the workers would.
 * - `down = true` refuses every publish, like a stopped broker.
 */
final class FakeMessagePublisher implements MessagePublisher
{
    /**
     * @var list<Message>
     */
    public array $published = [];

    public bool $deliver = false;

    public bool $down = false;

    public function publish(Message $message): void
    {
        if ($this->down) {
            throw new PublishFailed('The fake broker is down.');
        }

        $this->published[] = $message;

        if (! $this->deliver) {
            return;
        }

        foreach (QueueConfig::all() as $queue) {
            foreach ($queue->bindings as $pattern) {
                if (Str::is($pattern, $message->type)) {
                    $handler = app($queue->handler);
                    assert($handler instanceof MessageHandler);
                    $handler->handle($message);

                    break;
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_map(static fn (Message $message): string => $message->type, $this->published);
    }
}
