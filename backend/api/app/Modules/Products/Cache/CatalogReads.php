<?php

declare(strict_types=1);

namespace App\Modules\Products\Cache;

use App\Modules\Products\Http\Resources\ProductResource;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read side of the public catalogue, through CatalogCache. Everything
 * returned here is stock-free: the controllers add the live stock.
 *
 * Not cached: name searches (`q`: free text, an unbounded key space) and
 * pages after MAX_CACHED_PAGE (rarely read; caching them would only let a
 * client walking page numbers fill Redis).
 */
final readonly class CatalogReads
{
    public const int MAX_CACHED_PAGE = 50;

    public function __construct(
        private CatalogCache $cache,
        private ProductResource $resource,
    ) {}

    /**
     * @return list<array{id: string, slug: string, name: string}>
     */
    public function categories(): array
    {
        return $this->cache->remember('categories', fn (): array => array_values(Category::query()->orderBy('name')->get(['id', 'slug', 'name'])
            ->map(fn (Category $category): array => ['id' => $category->id, 'slug' => $category->slug, 'name' => $category->name])
            ->values()
            ->all()));
    }

    /**
     * One page of the catalogue, newest first.
     *
     * @return array{items: list<array<string, mixed>>, ids: list<string>, total: int}
     */
    public function page(?string $category, ?string $search, int $page, int $perPage): array
    {
        $load = fn (): array => $this->load($category, $search, $page, $perPage);

        if ($search !== null || $page > self::MAX_CACHED_PAGE) {
            return $load();
        }

        return $this->cache->remember('products:'.($category ?? '*').':'.$page.':'.$perPage, $load);
    }

    /**
     * @return array<string, mixed>|null null when the product does not exist
     */
    public function product(string $id): ?array
    {
        return $this->cache->rememberFound('product:'.$id, function () use ($id): ?array {
            $product = Product::query()->with('category')->find($id);

            return $product === null ? null : $this->resource->withoutStock($product);
        });
    }

    /**
     * @return array{items: list<array<string, mixed>>, ids: list<string>, total: int}
     */
    private function load(?string $category, ?string $search, int $page, int $perPage): array
    {
        $paginator = Product::query()
            ->with('category')
            ->when($category !== null, fn (Builder $query) => $query->whereHas('category', fn (Builder $c) => $c->where('slug', $category)))
            ->when($search !== null, fn (Builder $query) => $query->whereLike('name', '%'.addcslashes((string) $search, '%_\\').'%'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        /** @var list<Product> $products */
        $products = array_values($paginator->items());

        return [
            'items' => array_map(fn (Product $product): array => $this->resource->withoutStock($product), $products),
            'ids' => array_map(static fn (Product $product): string => $product->id, $products),
            'total' => $paginator->total(),
        ];
    }
}
