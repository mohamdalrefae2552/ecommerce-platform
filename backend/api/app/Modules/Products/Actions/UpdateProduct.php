<?php

declare(strict_types=1);

namespace App\Modules\Products\Actions;

use App\Modules\Products\Models\Product;

/**
 * Applies a partial edit to a product. The catalogue cache is invalidated
 * by the model observer once the change is committed, so every write path
 * (this action, a seeder, a console fix) is covered.
 */
final readonly class UpdateProduct
{
    /**
     * @param  array<string, string|int>  $changes  validated fields of UpdateProductRequest
     */
    public function handle(Product $product, array $changes): Product
    {
        $product->fill($changes);
        $product->save();

        return $product->load('category');
    }
}
