<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Request correlation id (docs/01 section 7, contract header XRequestId).
 *
 * Keeps the incoming X-Request-Id (set by edge) when it is a sane token —
 * the same rule edge applies — and otherwise generates a ULID. The id is
 * added to the log context and sent back on every response.
 */
final class RequestId
{
    public const string HEADER = 'X-Request-Id';

    public const string CONTEXT_KEY = 'request_id';

    private const string ACCEPTED = '/^[A-Za-z0-9._-]{1,128}$/';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get(self::HEADER);
        $id = is_string($incoming) && preg_match(self::ACCEPTED, $incoming) === 1
            ? $incoming
            : (string) Str::ulid();

        $request->headers->set(self::HEADER, $id);
        Context::add(self::CONTEXT_KEY, $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }
}
