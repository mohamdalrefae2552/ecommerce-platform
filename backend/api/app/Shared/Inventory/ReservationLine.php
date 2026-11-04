<?php

declare(strict_types=1);

namespace App\Shared\Inventory;

final readonly class ReservationLine
{
    public function __construct(
        public string $productId,
        public int $quantity,
    ) {}
}
