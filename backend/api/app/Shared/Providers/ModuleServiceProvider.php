<?php

declare(strict_types=1);

namespace App\Shared\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Base provider of every module (docs/01 section 3): registers the module's
 * routes under /api/v1, its migrations and its policies. A module never
 * registers anything for another module.
 */
abstract class ModuleServiceProvider extends ServiceProvider
{
    final public const string API_PREFIX = 'api/v1';

    /**
     * Absolute path of the module root (app/Modules/<Module>).
     */
    abstract protected function modulePath(): string;

    /**
     * Model class => policy class.
     *
     * @return array<class-string, class-string>
     */
    protected function policies(): array
    {
        return [];
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom($this->modulePath().'/Database/Migrations');

        foreach ($this->policies() as $model => $policy) {
            Gate::policy($model, $policy);
        }

        Route::middleware('api')
            ->prefix(self::API_PREFIX)
            ->group(function (): void {
                $this->loadRoutesFrom($this->modulePath().'/routes.php');
            });
    }
}
