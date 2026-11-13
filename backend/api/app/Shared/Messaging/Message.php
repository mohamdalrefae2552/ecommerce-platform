<?php

declare(strict_types=1);

namespace App\Shared\Messaging;

/**
 * One integration event. `id` is unique for ever: consumers use it to stay
 * idempotent, because delivery is at least once.
 */
final readonly class Message
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $id,
        public string $type,
        public array $payload,
        public string $occurredAt,
    ) {}

    public function string(string $key): string
    {
        $value = $this->payload[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new InvalidMessage('Message '.$this->id.' ('.$this->type.') has no "'.$key.'".');
        }

        return $value;
    }
}
