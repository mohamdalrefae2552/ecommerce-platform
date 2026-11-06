<?php

declare(strict_types=1);

namespace App\Modules\Products\Http\Controllers;

use App\Modules\Products\Cache\CatalogReads;
use App\Modules\Products\Http\Resources\ProductResource;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Stock\StockLookup;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/products/{productId} (getProduct): the details (cached) with
 * the stock asked live from the inventory (R2-F2). Never fails because of
 * the inventory.
 */
final readonly class ShowProductController
{
    public function __construct(
        private CatalogReads $catalog,
        private StockLookup $stock,
    ) {}

    public function __invoke(string $productId): JsonResponse
    {
        $id = strtoupper($productId);
        $product = $this->catalog->product($id) ?? throw (new ModelNotFoundException)->setModel(Product::class, [$id]);
        $stock = $this->stock->forProducts([$id]);

        return new JsonResponse(['data' => ProductResource::withStock($product, $stock->of($id))], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
