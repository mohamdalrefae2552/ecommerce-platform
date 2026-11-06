<?php

declare(strict_types=1);

namespace App\Modules\Products\Http\Controllers;

use App\Modules\Products\Cache\CatalogReads;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/categories (listCategories): the fixed list, ordered by name
 * (cached; invalidated when a category is written).
 */
final readonly class ListCategoriesController
{
    public function __construct(private CatalogReads $catalog) {}

    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['data' => $this->catalog->categories()], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
