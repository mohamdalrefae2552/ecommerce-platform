<?php

declare(strict_types=1);

namespace App\Modules\Products\Http\Resources;

use App\Modules\Products\Images\ProductImageStore;
use App\Modules\Products\Models\Product;

/**
 * Schema Product. The stock block is the inventory service's answer
 * (`unknown` / null when it is not available).
 */
final readonly class ProductResource
{
    public function __construct(private ProductImageStore $images) {}

    /**
     * @param  array{status: string, available: int|null}  $stock
     * @return array<string, mixed>
     */
    public function toArray(Product $product, array $stock): array
    {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'price' => ['amount_minor' => $product->price_minor, 'currency' => $product->currency],
            'category' => [
                'id' => $product->category->id,
                'slug' => $product->category->slug,
                'name' => $product->category->name,
            ],
            'image_url' => $this->images->url($product->image),
            'stock' => $stock,
            'created_at' => $product->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
