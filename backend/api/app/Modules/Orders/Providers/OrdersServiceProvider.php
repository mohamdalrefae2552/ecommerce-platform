<?php

declare(strict_types=1);

namespace App\Modules\Orders\Providers;

use App\Shared\Providers\ModuleServiceProvider;

final class OrdersServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return dirname(__DIR__);
    }
}
