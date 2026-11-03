<?php

declare(strict_types=1);

namespace App\Modules\Products\Database\Factories;

use App\Modules\Products\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
final class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $word = fake()->unique()->word();

        return ['slug' => Str::slug($word).'-'.Str::lower(Str::random(4)), 'name' => Str::ucfirst($word)];
    }
}
