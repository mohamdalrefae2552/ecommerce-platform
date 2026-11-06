<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP ETag for the public catalogue reads (requirement 3, R3-6), route
 * middleware `etag`: a strong ETag is the SHA-256 of the exact body, so it
 * changes whenever anything shown changes (stock included). A client that
 * sends the same value back in If-None-Match gets 304 with no body.
 *
 * `Cache-Control: no-cache, private`: the browser may keep the copy but must
 * revalidate before every use, so an edited product is never shown from a
 * stale copy; shared caches do not store it. The response is the same for
 * every caller (no user data), which is why SecurityHeaders keeps this
 * policy even when a token is sent. It must never be put on per-user
 * endpoints (orders, notifications, auth/me): those stay `no-store`.
 *
 * The 304 saves the transfer and the client's work, not the server's: the
 * response is still built to compare its tag (the catalogue cache is what
 * makes that cheap).
 */
final class EntityTag
{
    /** Request attribute read by SecurityHeaders. */
    public const string PUBLIC_REVALIDATE = 'cache.public_revalidate';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethodCacheable() || $response->getStatusCode() !== Response::HTTP_OK) {
            return $response;
        }

        $content = $response->getContent();

        if ($content === false) {
            return $response;
        }

        $request->attributes->set(self::PUBLIC_REVALIDATE, true);
        $response->setEtag(hash('sha256', $content));
        $response->headers->set('Cache-Control', 'no-cache, private');
        $response->isNotModified($request);

        return $response;
    }
}
