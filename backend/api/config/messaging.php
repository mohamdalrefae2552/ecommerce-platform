<?php

declare(strict_types=1);

use App\Modules\Notifications\Messaging\SendOrderNotification;
use App\Modules\Orders\Realtime\BroadcastOrderUpdate;

// Requirement 4 (ADR-0015): order events go to RabbitMQ through the outbox
// and are consumed by independent workers (`php artisan messaging:consume`).
return [
    'amqp' => [
        'host' => env('RABBITMQ_HOST', 'rabbitmq'),
        'port' => (int) env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'app'),
        'password' => env('RABBITMQ_PASSWORD', ''),
        'vhost' => env('RABBITMQ_VHOST', '/'),
        // Deadlines (golden rule 6): a broker that is down or slow never
        // holds a request; the outbox relay publishes later.
        'connect_timeout_seconds' => (float) env('RABBITMQ_CONNECT_TIMEOUT', 1.0),
        'io_timeout_seconds' => (float) env('RABBITMQ_IO_TIMEOUT', 3.0),
        'confirm_timeout_seconds' => (float) env('RABBITMQ_CONFIRM_TIMEOUT', 3.0),
    ],

    // Topic exchange every order event is published to (routing key = type).
    'exchange' => 'orders.events',

    // One durable queue per consumer. Each also gets `<queue>.retry` (waits
    // retry_delay_ms, then returns to the queue) and `<queue>.dlq` (parked
    // after max_attempts failures).
    'queues' => [
        'notifications.mail' => [
            'bindings' => ['order.*'],
            'handler' => SendOrderNotification::class,
            'max_attempts' => (int) env('MAIL_QUEUE_MAX_ATTEMPTS', 5),
            'retry_delay_ms' => (int) env('MAIL_QUEUE_RETRY_DELAY_MS', 10000),
        ],
        'realtime.orders' => [
            'bindings' => ['order.*'],
            'handler' => BroadcastOrderUpdate::class,
            'max_attempts' => 3,
            'retry_delay_ms' => 2000,
        ],
    ],

    'outbox' => [
        // The relay picks up rows the request could not publish itself.
        'relay_after_seconds' => 3,
        'relay_batch' => 100,
        'keep_published_hours' => 24,
    ],
];
