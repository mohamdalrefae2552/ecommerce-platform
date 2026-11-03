<?php

declare(strict_types=1);

namespace App\Modules\Products\Stock;

/**
 * Stock shown with products (R2-F2). Product reads never fail because of
 * the inventory: without an answer every product is `unknown` and the
 * caller reports the inventory as degraded.
 *
 * Until the gRPC InventoryGateway exists (task P2-API-01) there is no
 * answer, so every lookup is degraded.
 */
final readonly class StockLookup
{
    /**
     * @param  list<string>  $productIds
     */
    public function forProducts(array $productIds): StockSnapshot
    {
        return StockSnapshot::unavailable($productIds);
    }
}
