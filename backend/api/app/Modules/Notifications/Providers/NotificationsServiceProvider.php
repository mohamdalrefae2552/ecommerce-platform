<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Providers;

use App\Modules\Notifications\Listeners\NotifyCustomer;
use App\Modules\Orders\Contracts\Events\OrderPlaced;
use App\Modules\Orders\Contracts\Events\OrderStatusChanged;
use App\Shared\Providers\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

final class NotificationsServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        Event::listen(OrderPlaced::class, [NotifyCustomer::class, 'handleOrderPlaced']);
        Event::listen(OrderStatusChanged::class, [NotifyCustomer::class, 'handleOrderStatusChanged']);
    }

    protected function modulePath(): string
    {
        return dirname(__DIR__);
    }
}
