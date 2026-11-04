<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * An order was saved. Dispatched only after the transaction commits.
 */
final readonly class OrderPlaced implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $orderId,
        public string $userId,
        public string $status,
    ) {}
}
