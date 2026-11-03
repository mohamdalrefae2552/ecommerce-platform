<?php

declare(strict_types=1);

namespace App\Modules\Users\Providers;

use App\Shared\Providers\ModuleServiceProvider;

final class UsersServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return dirname(__DIR__);
    }
}
