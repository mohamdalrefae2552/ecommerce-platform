<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Providers;

use App\Shared\Providers\ModuleServiceProvider;

final class NotificationsServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return dirname(__DIR__);
    }
}
