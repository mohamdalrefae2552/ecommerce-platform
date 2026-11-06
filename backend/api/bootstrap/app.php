<?php

declare(strict_types=1);

use App\Shared\Http\Middleware\EnforceRequestBody;
use App\Shared\Http\Middleware\EntityTag;
use App\Shared\Http\Middleware\ForceJsonResponse;
use App\Shared\Http\Middleware\ReadYourWrites;
use App\Shared\Http\Middleware\RequestId;
use App\Shared\Http\Middleware\SecurityHeaders;
use App\Shared\Http\Problems\DomainProblem;
use App\Shared\Http\Problems\ProblemRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

// API-only application: no web routes, no sessions, no views. Every module
// registers its own routes under /api/v1 (App\Shared\Providers\ModuleServiceProvider).
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global, so every response (404/405 and errors included) carries
        // X-Request-Id and the security headers. RequestId runs first.
        $middleware->prepend([RequestId::class, SecurityHeaders::class, ForceJsonResponse::class]);
        // ReadYourWrites: a caller that just wrote reads from the primary (ADR-0014).
        $middleware->api(append: [EnforceRequestBody::class, ReadYourWrites::class]);
        // ETag + 304 for the public catalogue reads only (requirement 3).
        $middleware->alias(['etag' => EntityTag::class]);
        // General limit: 120 per minute per user or IP (docs/04 section 3).
        $middleware->throttleApi();
        // php-fpm is reached over FastCGI from edge: REMOTE_ADDR is the real
        // client and edge drops forwarding headers, so no proxy is trusted.
        $middleware->trustProxies(at: []);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Domain problems are expected outcomes (4xx, inventory 503), not bugs.
        $exceptions->dontReport(DomainProblem::class);
        $exceptions->render(new ProblemRenderer);
    })
    ->create();
