<?php

declare(strict_types=1);

namespace App\Modules\Products\Http\Controllers;

use App\Modules\Products\Models\Category;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/categories (listCategories): the fixed list, ordered by name.
 */
final readonly class ListCategoriesController
{
    public function __invoke(): JsonResponse
    {
        $categories = Category::query()->orderBy('name')->get(['id', 'slug', 'name'])
            ->map(fn (Category $category): array => ['id' => $category->id, 'slug' => $category->slug, 'name' => $category->name])
            ->values()
            ->all();

        return new JsonResponse(['data' => $categories], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
