<?php

declare(strict_types=1);

namespace App\Modules\Products\Providers;

use App\Modules\Products\Catalog\EloquentProductCatalog;
use App\Modules\Products\Contracts\ProductCatalog;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Policies\ProductPolicy;
use App\Shared\Providers\ModuleServiceProvider;

final class ProductsServiceProvider extends ModuleServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $singletons = [ProductCatalog::class => EloquentProductCatalog::class];

    protected function modulePath(): string
    {
        return dirname(__DIR__);
    }

    protected function policies(): array
    {
        return [Product::class => ProductPolicy::class];
    }
}
