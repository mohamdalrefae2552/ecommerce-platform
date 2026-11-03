<?php

declare(strict_types=1);

namespace App\Modules\Products\Http\Controllers;

use App\Modules\Products\Http\Resources\ProductResource;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Stock\StockLookup;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/products/{productId} (getProduct): the details with the stock
 * asked from the inventory (R2-F2). Never fails because of the inventory.
 */
final readonly class ShowProductController
{
    public function __construct(
        private StockLookup $stock,
        private ProductResource $resource,
    ) {}

    public function __invoke(string $productId): JsonResponse
    {
        $product = Product::query()->with('category')->findOrFail(strtoupper($productId));
        $stock = $this->stock->forProducts([$product->id]);

        return new JsonResponse(['data' => $this->resource->toArray($product, $stock->of($product->id))], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
