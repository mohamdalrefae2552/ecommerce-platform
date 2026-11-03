<?php

declare(strict_types=1);

namespace App\Shared\Http\Pagination;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * List envelope of the contract: `{data, meta:{page, per_page, total,
 * last_page[, degraded]}, links:{first, prev, next, last}}`. Links keep the
 * request's filters.
 */
final class PageEnvelope
{
    /**
     * @template TItem
     *
     * @param  LengthAwarePaginator<int, TItem>  $page
     * @param  list<array<string, mixed>>  $items
     * @param  list<string>  $degraded
     */
    public static function response(Request $request, LengthAwarePaginator $page, array $items, array $degraded = []): JsonResponse
    {
        $lastPage = max(1, $page->lastPage());
        $current = $page->currentPage();

        $meta = [
            'page' => $current,
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'last_page' => $lastPage,
        ];

        if ($degraded !== []) {
            $meta['degraded'] = $degraded;
        }

        return new JsonResponse([
            'data' => $items,
            'meta' => $meta,
            'links' => [
                'first' => self::url($request, 1, $page->perPage()),
                'prev' => $current > 1 ? self::url($request, min($current - 1, $lastPage), $page->perPage()) : null,
                'next' => $current < $lastPage ? self::url($request, $current + 1, $page->perPage()) : null,
                'last' => self::url($request, $lastPage, $page->perPage()),
            ],
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function url(Request $request, int $page, int $perPage): string
    {
        $query = [...$request->query(), 'page' => $page, 'per_page' => $perPage];

        return $request->url().'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
