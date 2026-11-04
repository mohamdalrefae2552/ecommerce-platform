<?php

declare(strict_types=1);

namespace App\Modules\Products\Http\Controllers;

use App\Modules\Products\Http\Requests\AdjustStockRequest;
use App\Modules\Products\Models\Product;
use App\Shared\Inventory\InventoryGateway;
use Illuminate\Http\JsonResponse;
use Ramsey\Uuid\Uuid;

/**
 * PUT /api/v1/products/{productId}/stock (adjustProductStock): admin only.
 * Inventory management lives in the inventory service (D-01): this only
 * forwards AdjustStock. The adjustment id is derived from the product and
 * the Idempotency-Key, so a retried request is applied once. A write-off
 * larger than the stock is 409 INSUFFICIENT_STOCK; an unreachable
 * inventory is 503 and nothing changes.
 */
final readonly class AdjustStockController
{
    public function __construct(private InventoryGateway $inventory) {}

    public function __invoke(AdjustStockRequest $request, string $productId): JsonResponse
    {
        $product = Product::query()->findOrFail(strtoupper($productId), ['id']);
        $adjustmentId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'urn:ecommerce:product:'.$product->id.':adjustment:'.$request->idempotencyKey())->toString();

        $available = $this->inventory->adjust($adjustmentId, $product->id, $request->delta(), $request->note());

        return new JsonResponse(['data' => ['product_id' => $product->id, 'available' => $available]]);
    }
}
