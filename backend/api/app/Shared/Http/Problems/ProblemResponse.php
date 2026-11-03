<?php

declare(strict_types=1);

namespace App\Shared\Http\Problems;

use App\Shared\Http\Middleware\RequestId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Builds an RFC 9457 `application/problem+json` response (contracts/errors.md).
 */
final class ProblemResponse
{
    public const string CONTENT_TYPE = 'application/problem+json';

    /**
     * @param  array<string, mixed>  $extensions
     * @param  array<string, string>  $headers
     */
    public static function make(
        Request $request,
        ProblemCode $code,
        ?string $detail = null,
        array $extensions = [],
        array $headers = [],
    ): JsonResponse {
        $traceId = $request->headers->get(RequestId::HEADER);

        if (! is_string($traceId) || $traceId === '') {
            $traceId = (string) Str::ulid();
            $request->headers->set(RequestId::HEADER, $traceId);
        }

        $body = [
            'type' => $code->type(),
            'title' => $code->title(),
            'status' => $code->status(),
            'detail' => $detail ?? $code->defaultDetail(),
            'instance' => '/'.ltrim($request->getPathInfo(), '/'),
            'code' => $code->value,
            'trace_id' => $traceId,
            ...$extensions,
        ];

        if ($code->status() === 401) {
            $headers['WWW-Authenticate'] ??= 'Bearer';
        }

        $response = new JsonResponse($body, $code->status(), $headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response->headers->set('Content-Type', self::CONTENT_TYPE);
        $response->headers->set(RequestId::HEADER, $traceId);

        return $response;
    }
}
