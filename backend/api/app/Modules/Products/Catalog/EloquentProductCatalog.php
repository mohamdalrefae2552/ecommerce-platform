<?php

declare(strict_types=1);

namespace App\Modules\Products\Catalog;

use App\Modules\Products\Contracts\CatalogProduct;
use App\Modules\Products\Contracts\ProductCatalog;
use App\Modules\Products\Models\Product;

final readonly class EloquentProductCatalog implements ProductCatalog
{
    public function findMany(array $ids): array
    {
        $products = [];

        foreach (Product::query()->whereIn('id', $ids)->get(['id', 'name', 'price_minor', 'currency']) as $product) {
            $products[$product->id] = new CatalogProduct($product->id, $product->name, $product->price_minor, $product->currency);
        }

        return $products;
    }
}
