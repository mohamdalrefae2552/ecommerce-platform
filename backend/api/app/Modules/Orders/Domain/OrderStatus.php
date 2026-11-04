<?php

declare(strict_types=1);

namespace App\Modules\Orders\Domain;

/**
 * Order state machine (docs/02 Orders):
 *
 *   pending ──► confirmed ──► shipped ──► delivered
 *      │            │
 *      └────────────┴──► cancelled
 *
 * Any other transition (including staying in the same state) is refused
 * with 409 ORDER_INVALID_TRANSITION.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Pending => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Shipped, self::Cancelled],
            self::Shipped => [self::Delivered],
            self::Delivered, self::Cancelled => [],
        }, true);
    }
}
