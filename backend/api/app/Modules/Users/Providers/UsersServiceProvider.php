<?php

declare(strict_types=1);

namespace App\Modules\Users\Providers;

use App\Modules\Users\Console\CreateAdmin;
use App\Modules\Users\Console\IssueExpiredToken;
use App\Modules\Users\Contracts\UserDirectory;
use App\Modules\Users\Directory\EloquentUserDirectory;
use App\Shared\Providers\ModuleServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

final class UsersServiceProvider extends ModuleServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $singletons = [UserDirectory::class => EloquentUserDirectory::class];

    /**
     * Cache key of the per-(email, IP) login limiter as ThrottleRequests
     * stores it (keys are not hashed: AuthServiceProvider), so a successful
     * login can clear it.
     */
    public static function loginAttemptKey(string $email, string $ip): string
    {
        return 'login:'.self::loginKey($email, $ip);
    }

    public function boot(): void
    {
        parent::boot();

        // docs/04 section 3 (R1-SEC2).
        RateLimiter::for('login', fn (Request $request): array => [
            Limit::perMinute(5)->by(self::loginKey(mb_strtolower(trim($request->string('email')->toString())), (string) $request->ip())),
            Limit::perMinute(20)->by('login-ip:'.$request->ip()),
        ]);
        RateLimiter::for('register', fn (Request $request): Limit => Limit::perHour(5)->by('register:'.$request->ip()));
        RateLimiter::for('refresh', fn (Request $request): Limit => Limit::perMinute(30)->by('refresh:'.$request->ip()));

        if ($this->app->runningInConsole()) {
            $this->commands([CreateAdmin::class, IssueExpiredToken::class]);
        }
    }

    protected function modulePath(): string
    {
        return dirname(__DIR__);
    }

    private static function loginKey(string $email, string $ip): string
    {
        return 'login:'.$email.'|'.$ip;
    }
}
