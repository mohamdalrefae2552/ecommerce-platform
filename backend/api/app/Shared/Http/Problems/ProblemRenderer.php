<?php

declare(strict_types=1);

namespace App\Shared\Http\Problems;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Central exception renderer (bootstrap/app.php): every error leaves the API
 * as problem+json with a catalog code. Unknown failures become 500
 * INTERNAL_ERROR without any message, trace, SQL or path, whatever APP_DEBUG is.
 *
 * Laravel has already converted AuthorizationException (403, or 404 with
 * denyAsNotFound) and ModelNotFoundException (404) into HTTP exceptions.
 */
final class ProblemRenderer
{
    private const array HEADERS_KEPT = ['Allow', 'Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'];

    public function __invoke(Throwable $e, Request $request): JsonResponse
    {
        return match (true) {
            $e instanceof DomainProblem => ProblemResponse::make(
                $request,
                $e->problemCode(),
                $e->problemDetail(),
                $e->problemExtensions(),
                $e->problemHeaders(),
            ),
            $e instanceof ValidationException => ProblemResponse::make(
                $request,
                ProblemCode::ValidationFailed,
                extensions: ['errors' => $e->errors()],
            ),
            $e instanceof AuthenticationException => ProblemResponse::make($request, ProblemCode::Unauthenticated),
            $e instanceof HttpExceptionInterface => $this->fromHttpException($e, $request),
            default => ProblemResponse::make($request, ProblemCode::InternalError),
        };
    }

    private function fromHttpException(HttpExceptionInterface $e, Request $request): JsonResponse
    {
        $code = match ($e->getStatusCode()) {
            400 => ProblemCode::MalformedRequest,
            401 => ProblemCode::Unauthenticated,
            403 => ProblemCode::Forbidden,
            404 => ProblemCode::NotFound,
            405 => ProblemCode::MethodNotAllowed,
            413 => ProblemCode::PayloadTooLarge,
            415 => ProblemCode::UnsupportedMediaType,
            429 => ProblemCode::RateLimited,
            default => ProblemCode::InternalError,
        };

        $headers = [];
        foreach ($e->getHeaders() as $name => $value) {
            if (is_string($name) && in_array($name, self::HEADERS_KEPT, true) && is_scalar($value)) {
                $headers[$name] = (string) $value;
            }
        }

        if ($code === ProblemCode::RateLimited) {
            $retryAfter = max(1, (int) ($headers['Retry-After'] ?? 1));
            $headers['Retry-After'] = (string) $retryAfter;

            return ProblemResponse::make(
                $request,
                $code,
                'محاولات كثيرة، حاول بعد '.$retryAfter.' ثانية.',
                ['retry_after' => $retryAfter],
                $headers,
            );
        }

        return ProblemResponse::make($request, $code, headers: $headers);
    }
}
