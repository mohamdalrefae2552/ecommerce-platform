<?php

declare(strict_types=1);

namespace App\Shared\Inventory;

/**
 * A line the inventory could not serve (contract schema Shortage).
 */
final readonly class Shortage
{
    public function __construct(
        public string $productId,
        public int $requested,
        public int $available,
    ) {}

    /**
     * @return array{product_id: string, requested: int, available: int}
     */
    public function toArray(): array
    {
        return ['product_id' => $this->productId, 'requested' => $this->requested, 'available' => $this->available];
    }
}
