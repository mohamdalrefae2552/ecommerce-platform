<?php

declare(strict_types=1);

namespace App\Shared\Database;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

/**
 * Eloquent guard rails (requirement 3, N+1): outside production a relation
 * read without eager loading throws instead of silently running one query
 * per row, so an N+1 introduced in any list fails the test suite. In
 * production it is only a missed optimisation, never an error for a user.
 */
final class DatabaseServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
    }
}
