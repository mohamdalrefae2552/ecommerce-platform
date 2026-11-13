<?php

declare(strict_types=1);

namespace App\Shared\Realtime;

use App\Shared\Providers\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the channel authorisation endpoint. The channels themselves and
 * their rules are registered by the modules that own them.
 */
final class RealtimeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // A page subscribes once or twice; 60 a minute stops channel-name guessing.
        RateLimiter::for('channel-auth', static fn (Request $request): Limit => Limit::perMinute(60)->by('channel-auth:'.$request->ip()));

        Route::middleware(['api', 'auth', 'throttle:channel-auth'])
            ->prefix(ModuleServiceProvider::API_PREFIX)
            ->post('/broadcasting/auth', AuthorizeChannelController::class);
    }
}
