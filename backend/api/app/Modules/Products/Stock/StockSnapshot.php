<?php

declare(strict_types=1);

namespace App\Modules\Products\Stock;

/**
 * Stock of a set of products as the API shows it (schema Stock).
 */
final readonly class StockSnapshot
{
    /**
     * @param  array<string, int|null>  $available  product id ⇒ units (null = unknown)
     */
    private function __construct(
        private array $available,
        public bool $degraded,
    ) {}

    /**
     * @param  array<string, int>  $available
     */
    public static function known(array $available): self
    {
        return new self($available, false);
    }

    /**
     * @param  list<string>  $productIds
     */
    public static function unavailable(array $productIds): self
    {
        return new self(array_fill_keys($productIds, null), true);
    }

    /**
     * @return array{status: string, available: int|null}
     */
    public function of(string $productId): array
    {
        $available = $this->available[$productId] ?? null;

        return match (true) {
            $available === null => ['status' => 'unknown', 'available' => null],
            $available > 0 => ['status' => 'in_stock', 'available' => $available],
            default => ['status' => 'out_of_stock', 'available' => 0],
        };
    }
}
