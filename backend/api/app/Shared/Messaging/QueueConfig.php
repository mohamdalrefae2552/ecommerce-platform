<?php

declare(strict_types=1);

namespace App\Shared\Messaging;

use InvalidArgumentException;

/**
 * Settings of one consumer queue (config/messaging.php).
 */
final readonly class QueueConfig
{
    /**
     * @param  list<string>  $bindings
     * @param  class-string<MessageHandler>  $handler
     */
    public function __construct(
        public string $name,
        public array $bindings,
        public string $handler,
        public int $maxAttempts,
        public int $retryDelayMs,
    ) {}

    public function retryQueue(): string
    {
        return $this->name.'.retry';
    }

    public function deadLetterQueue(): string
    {
        return $this->name.'.dlq';
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        $queues = config('messaging.queues');
        $all = [];

        foreach (is_array($queues) ? $queues : [] as $name => $settings) {
            $all[] = self::from((string) $name, is_array($settings) ? $settings : []);
        }

        return $all;
    }

    public static function named(string $name): self
    {
        foreach (self::all() as $queue) {
            if ($queue->name === $name) {
                return $queue;
            }
        }

        throw new InvalidArgumentException('Unknown queue "'.$name.'" (config/messaging.php).');
    }

    /**
     * @param  array<array-key, mixed>  $settings
     */
    private static function from(string $name, array $settings): self
    {
        $handler = $settings['handler'] ?? null;
        $bindings = $settings['bindings'] ?? null;
        $maxAttempts = $settings['max_attempts'] ?? null;
        $retryDelayMs = $settings['retry_delay_ms'] ?? null;

        if (! is_string($handler) || ! is_subclass_of($handler, MessageHandler::class)
            || ! is_array($bindings) || ! is_int($maxAttempts) || $maxAttempts < 1
            || ! is_int($retryDelayMs) || $retryDelayMs < 1) {
            throw new InvalidArgumentException('Queue "'.$name.'" is not configured correctly (config/messaging.php).');
        }

        return new self($name, array_values(array_filter($bindings, is_string(...))), $handler, $maxAttempts, $retryDelayMs);
    }
}
