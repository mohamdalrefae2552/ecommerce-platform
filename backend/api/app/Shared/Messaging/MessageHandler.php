<?php

declare(strict_types=1);

namespace App\Shared\Messaging;

/**
 * Handles one message of a queue. Must be idempotent (the same message can
 * arrive twice). Throwing means "try again later"; InvalidMessage means
 * "never".
 */
interface MessageHandler
{
    public function handle(Message $message): void;
}
