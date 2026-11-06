<?php

declare(strict_types=1);

namespace App\Modules\Products\Http\Controllers;

use App\Modules\Products\Actions\UpdateProduct;
use App\Modules\Products\Http\Requests\UpdateProductRequest;
use App\Modules\Products\Http\Resources\ProductResource;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Stock\StockLookup;
use Illuminate\Http\JsonResponse;

/**
 * PATCH /api/v1/products/{productId} (updateProduct): admin only (route
 * middleware `can:update`, checked before the body is validated). Answers
 * with the product as the next read will show it, live stock included.
 */
final readonly class UpdateProductController
{
    public function __construct(
        private UpdateProduct $update,
        private StockLookup $stock,
        private ProductResource $resource,
    ) {}

    public function __invoke(UpdateProductRequest $request, string $productId): JsonResponse
    {
        $product = $this->update->handle(Product::query()->findOrFail(strtoupper($productId)), $request->changes());
        $stock = $this->stock->forProducts([$product->id]);

        return new JsonResponse(['data' => $this->resource->toArray($product, $stock->of($product->id))], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
