<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts\Messages;

/**
 * Integration events the Orders module publishes to RabbitMQ (exchange
 * `orders.events`, routing key = type). Payloads are documented in
 * docs/10-async-realtime.md section 3; other modules read them by key.
 *
 * order.placed          order_id, user_id, status, total_minor, currency,
 *                       item_count, created_at
 * order.status_changed  order_id, user_id, from, to, changed_at
 */
final class OrderMessages
{
    public const string PLACED = 'order.placed';

    public const string STATUS_CHANGED = 'order.status_changed';
}
