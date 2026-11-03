<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use App\Shared\Http\Problems\ProblemCode;
use App\Shared\Http\Problems\ProblemException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Request bodies are JSON, except the product image upload (multipart).
 * Any other media type ⇒ 415; a JSON body that does not parse ⇒ 400
 * (Laravel would otherwise silently read it as empty input).
 *
 * Runs in the "api" group, after routing, so unknown routes stay 404.
 */
final class EnforceRequestBody
{
    private const array ACCEPTED = ['application/json', 'multipart/form-data'];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $content = $request->getContent();

        if ($content === '' && $request->files->count() === 0) {
            return $next($request);
        }

        $mediaType = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type', ''))[0]));
        $isJson = $mediaType === 'application/json' || str_ends_with($mediaType, '+json');

        if (! $isJson && ! in_array($mediaType, self::ACCEPTED, true)) {
            throw new ProblemException(ProblemCode::UnsupportedMediaType);
        }

        if ($isJson && ! json_validate($content)) {
            throw new ProblemException(ProblemCode::MalformedRequest);
        }

        return $next($request);
    }
}
