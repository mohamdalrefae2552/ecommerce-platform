<?php

declare(strict_types=1);

namespace App\Modules\Products\Contracts;

/**
 * Public read interface of the Products module (docs/01 section 3).
 */
interface ProductCatalog
{
    /**
     * @param  list<string>  $ids
     * @return array<string, CatalogProduct> keyed by id; unknown ids are absent
     */
    public function findMany(array $ids): array;
}
