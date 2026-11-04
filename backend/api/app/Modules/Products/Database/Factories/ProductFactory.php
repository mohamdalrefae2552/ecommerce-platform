<?php

declare(strict_types=1);

namespace App\Modules\Products\Database\Factories;

use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
final class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => Str::ucfirst(fake()->word().' '.fake()->word()),
            'description' => fake()->sentence(),
            'price_minor' => fake()->numberBetween(100, 100_000_000),
            'category_id' => Category::factory(),
            'image' => Str::ulid().'.webp',
        ];
    }
}
