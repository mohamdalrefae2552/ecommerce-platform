<?php

declare(strict_types=1);

namespace App\Modules\Orders\Providers;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Policies\OrderPolicy;
use App\Shared\Auth\AuthUser;
use App\Shared\Providers\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class OrdersServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        // docs/04 section 3: 10 order creations per minute per user (API6).
        RateLimiter::for('orders-create', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(10)->by('orders-create:'.($user instanceof AuthUser ? $user->userId() : $request->ip()));
        });
    }

    protected function modulePath(): string
    {
        return dirname(__DIR__);
    }

    protected function policies(): array
    {
        return [Order::class => OrderPolicy::class];
    }
}
