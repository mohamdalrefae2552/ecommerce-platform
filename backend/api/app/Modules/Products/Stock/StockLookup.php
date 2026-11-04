<?php

declare(strict_types=1);

namespace App\Modules\Products\Stock;

use App\Shared\Inventory\Exceptions\InventoryContractViolation;
use App\Shared\Inventory\Exceptions\InventoryTimeout;
use App\Shared\Inventory\Exceptions\InventoryUnavailable;
use App\Shared\Inventory\InventoryGateway;

/**
 * Stock shown with products (R2-F2): one batched GetStock for the whole
 * page. Product reads never fail because of the inventory (PROOF-R2-2):
 * when it is down, slower than its 500 ms deadline or answers nonsense,
 * every product is `unknown` and the caller reports the inventory as
 * degraded.
 */
final readonly class StockLookup
{
    public function __construct(private InventoryGateway $inventory) {}

    /**
     * @param  list<string>  $productIds
     */
    public function forProducts(array $productIds): StockSnapshot
    {
        if ($productIds === []) {
            return StockSnapshot::known([]);
        }

        try {
            $levels = $this->inventory->stockFor($productIds);
        } catch (InventoryUnavailable|InventoryTimeout) {
            return StockSnapshot::unavailable($productIds);
        } catch (InventoryContractViolation $e) {
            report($e);

            return StockSnapshot::unavailable($productIds);
        }

        $available = [];
        foreach ($levels as $id => $level) {
            $available[$id] = $level->available;
        }

        return StockSnapshot::known($available);
    }
}
