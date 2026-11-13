<?php

declare(strict_types=1);

namespace App\Shared\Messaging;

/**
 * Publishes a message to the broker. Returns only once the broker confirmed
 * it stored the message; throws PublishFailed otherwise.
 */
interface MessagePublisher
{
    public function publish(Message $message): void;
}
