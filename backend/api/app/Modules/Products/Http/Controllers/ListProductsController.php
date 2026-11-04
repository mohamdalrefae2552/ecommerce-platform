<?php

declare(strict_types=1);

namespace App\Modules\Products\Http\Controllers;

use App\Modules\Products\Http\Requests\ListProductsRequest;
use App\Modules\Products\Http\Resources\ProductResource;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Stock\StockLookup;
use App\Shared\Http\Pagination\PageEnvelope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/products (listProducts): newest first, optional category and
 * name filter, one batched stock lookup for the whole page.
 */
final readonly class ListProductsController
{
    public function __construct(
        private StockLookup $stock,
        private ProductResource $resource,
    ) {}

    public function __invoke(ListProductsRequest $request): JsonResponse
    {
        $category = $request->filled('category') ? $request->string('category')->toString() : null;
        $search = $request->filled('q') ? $request->string('q')->toString() : null;

        $page = Product::query()
            ->with('category')
            ->when($category !== null, fn (Builder $query) => $query->whereHas('category', fn (Builder $c) => $c->where('slug', $category)))
            ->when($search !== null, fn (Builder $query) => $query->whereLike('name', '%'.addcslashes((string) $search, '%_\\').'%'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->perPage(), ['*'], 'page', $request->page());

        /** @var list<Product> $products */
        $products = array_values($page->items());
        $stock = $this->stock->forProducts(array_map(static fn (Product $p): string => $p->id, $products));

        return PageEnvelope::response(
            $request,
            $page,
            array_map(fn (Product $product): array => $this->resource->toArray($product, $stock->of($product->id)), $products),
            $stock->degraded ? ['inventory'] : [],
        );
    }
}
