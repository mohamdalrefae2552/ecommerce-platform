<?php

declare(strict_types=1);

namespace App\Shared\Auth;

use App\Shared\Auth\Console\GenerateJwtKeys;
use App\Shared\Http\Problems\DomainProblem;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Psr\Clock\ClockInterface;

/**
 * JWT authentication (ADR-0003): keys, issuer, verifier and the `jwt` guard,
 * plus the general `api` rate limiter (120 per minute per user or IP).
 */
final class AuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ClockInterface::class, CarbonClock::class);
        $this->app->singleton(JwtConfig::class, fn (Application $app): JwtConfig => JwtConfig::fromRepository($app->make(Repository::class)));
        $this->app->singleton(JwtKeyStore::class, fn (Application $app): JwtKeyStore => new JwtKeyStore($app->make(JwtConfig::class)->keysPath));
        $this->app->singleton(TokenIssuer::class);
        $this->app->singleton(TokenVerifier::class);
    }

    public function boot(): void
    {
        Auth::extend('jwt', function (Application $app, string $name, array $config): JwtGuard {
            $provider = Auth::createUserProvider(is_string($config['provider'] ?? null) ? $config['provider'] : null);

            if ($provider === null) {
                throw new LogicException('The jwt guard needs a user provider.');
            }

            $guard = new JwtGuard($provider, $app->make(TokenVerifier::class), $app->make(Request::class));
            $app->refresh('request', $guard, 'setRequest');

            return $guard;
        });

        // Readable limiter keys (`<limiter>:<key>`), so a module can clear one.
        ThrottleRequests::shouldHashKeys(false);

        RateLimiter::for('api', function (Request $request): Limit {
            try {
                $user = $request->user();
            } catch (DomainProblem) {
                // Expired token: the endpoint itself decides whether that matters.
                $user = null;
            }

            return Limit::perMinute(120)->by($user instanceof AuthUser ? 'user:'.$user->userId() : 'ip:'.$request->ip());
        });

        if ($this->app->runningInConsole()) {
            $this->commands([GenerateJwtKeys::class]);
        }
    }
}
