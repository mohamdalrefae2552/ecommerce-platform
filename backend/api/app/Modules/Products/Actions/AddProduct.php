<?php

declare(strict_types=1);

namespace App\Modules\Products\Actions;

use App\Modules\Products\Images\ProductImageStore;
use App\Modules\Products\Models\Product;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Adds a product with its image. The image file is removed again when the
 * row cannot be saved, so no orphan file is left in the volume.
 */
final readonly class AddProduct
{
    public function __construct(private ProductImageStore $images) {}

    public function handle(string $name, string $description, int $priceMinor, string $categoryId, UploadedFile $image): Product
    {
        $file = $this->images->store($image);

        try {
            $product = Product::query()->create([
                'name' => $name,
                'description' => $description,
                'price_minor' => $priceMinor,
                'category_id' => $categoryId,
                'image' => $file,
            ]);
        } catch (Throwable $e) {
            $this->images->delete($file);

            throw $e;
        }

        return $product->load('category');
    }
}
