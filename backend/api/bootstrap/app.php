<?php

declare(strict_types=1);

use App\Shared\Http\Middleware\RequestId;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;

// API-only application: no web routes, no sessions, no views. Every module
// registers its own routes under /api/v1 (App\Shared\Providers\ModuleServiceProvider).
// The problem+json exception renderer is added by P1-API-01.
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // First, so every response (errors included) carries X-Request-Id.
        $middleware->prepend(RequestId::class);
    })
    ->withExceptions()
    ->create();
