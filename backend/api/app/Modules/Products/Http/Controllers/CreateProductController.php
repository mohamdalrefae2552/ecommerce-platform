<?php

declare(strict_types=1);

namespace App\Modules\Products\Http\Controllers;

use App\Modules\Products\Actions\AddProduct;
use App\Modules\Products\Http\Requests\CreateProductRequest;
use App\Modules\Products\Http\Resources\ProductResource;
use App\Modules\Products\Stock\InitialStock;
use App\Modules\Products\Stock\StockLookup;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/products (createProduct): admin only (route middleware
 * `can:create`, checked before the body is validated).
 */
final readonly class CreateProductController
{
    public function __construct(
        private AddProduct $add,
        private StockLookup $stock,
        private InitialStock $initialStock,
        private ProductResource $resource,
    ) {}

    public function __invoke(CreateProductRequest $request): JsonResponse
    {
        $product = $this->add->handle(
            $request->string('name')->trim()->toString(),
            $request->string('description')->trim()->toString(),
            $request->integer('price_minor'),
            strtoupper($request->string('category_id')->toString()),
            $request->imageFile(),
        );

        $initial = $request->integer('initial_stock');
        $stock = $initial > 0
            ? $this->initialStock->register($product->id, $initial)
            : $this->stock->forProducts([$product->id]);

        return new JsonResponse(
            ['data' => $this->resource->toArray($product, $stock->of($product->id))],
            201,
            ['Location' => url('/api/v1/products/'.$product->id)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
