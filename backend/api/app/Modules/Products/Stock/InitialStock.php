<?php

declare(strict_types=1);

namespace App\Modules\Products\Stock;

use App\Shared\Inventory\Exceptions\InventoryContractViolation;
use App\Shared\Inventory\Exceptions\InventoryTimeout;
use App\Shared\Inventory\Exceptions\InventoryUnavailable;
use App\Shared\Inventory\InventoryGateway;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

/**
 * `initial_stock` of POST /products: sent with AdjustStock after the product
 * is saved. The adjustment id is derived from the product id, so a retry can
 * never add the initial quantity twice. If the inventory does not answer the
 * product still exists; the admin sets the quantity later with
 * PUT /products/{productId}/stock.
 */
final readonly class InitialStock
{
    public function __construct(private InventoryGateway $inventory) {}

    public function register(string $productId, int $quantity): StockSnapshot
    {
        $adjustmentId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'urn:ecommerce:product:'.$productId.':initial-stock')->toString();

        try {
            return StockSnapshot::known([$productId => $this->inventory->adjust($adjustmentId, $productId, $quantity, 'initial stock')]);
        } catch (InventoryUnavailable|InventoryTimeout|InventoryContractViolation $e) {
            Log::warning('Initial stock not registered: the inventory did not answer.', ['product_id' => $productId, 'error' => $e::class]);

            return StockSnapshot::unavailable([$productId]);
        }
    }
}
