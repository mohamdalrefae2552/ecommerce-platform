<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Orders\Models\Order;

/**
 * Result of PlaceOrder: `created` is false when an idempotent replay
 * returned an order created by an earlier request (200 instead of 201).
 */
final readonly class PlacedOrder
{
    public function __construct(
        public Order $order,
        public bool $created,
    ) {}
}
