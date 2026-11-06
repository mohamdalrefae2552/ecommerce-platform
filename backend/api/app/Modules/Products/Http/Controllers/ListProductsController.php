<?php

declare(strict_types=1);

namespace App\Modules\Products\Http\Controllers;

use App\Modules\Products\Cache\CatalogReads;
use App\Modules\Products\Http\Requests\ListProductsRequest;
use App\Modules\Products\Http\Resources\ProductResource;
use App\Modules\Products\Stock\StockLookup;
use App\Shared\Http\Pagination\PageEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * GET /api/v1/products (listProducts): newest first, optional category and
 * name filter. The page comes from the catalogue cache (stock-free); the
 * stock of the whole page is one batched, always-live lookup.
 */
final readonly class ListProductsController
{
    public function __construct(
        private CatalogReads $catalog,
        private StockLookup $stock,
    ) {}

    public function __invoke(ListProductsRequest $request): JsonResponse
    {
        $category = $request->filled('category') ? $request->string('category')->toString() : null;
        $search = $request->filled('q') ? $request->string('q')->toString() : null;

        $page = $this->catalog->page($category, $search, $request->page(), $request->perPage());
        $stock = $this->stock->forProducts($page['ids']);

        return PageEnvelope::response(
            $request,
            new LengthAwarePaginator([], $page['total'], $request->perPage(), $request->page()),
            array_map(static fn (array $product, string $id): array => ProductResource::withStock($product, $stock->of($id)), $page['items'], $page['ids']),
            $stock->degraded ? ['inventory'] : [],
        );
    }
}
