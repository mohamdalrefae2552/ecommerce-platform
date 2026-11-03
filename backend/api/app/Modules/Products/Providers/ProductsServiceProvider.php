<?php

declare(strict_types=1);

namespace App\Modules\Products\Providers;

use App\Shared\Providers\ModuleServiceProvider;

final class ProductsServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return dirname(__DIR__);
    }
}
