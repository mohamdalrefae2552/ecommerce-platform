<?php

declare(strict_types=1);

namespace App\Modules\Products\Contracts;

/**
 * What other modules may know about a product: its id, name and current
 * price (orders take a snapshot of it).
 */
final readonly class CatalogProduct
{
    public function __construct(
        public string $id,
        public string $name,
        public int $priceMinor,
        public string $currency,
    ) {}
}
