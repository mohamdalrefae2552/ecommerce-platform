<?php

declare(strict_types=1);

namespace App\Shared\Http\Pagination;

/**
 * Validation rules of the `page` / `per_page` query parameters (docs/02
 * section 1): out-of-range values are a 422, never silently clamped.
 */
final class PageRequest
{
    public const int DEFAULT_PER_PAGE = 20;

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'page' => ['bail', 'sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['bail', 'sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
