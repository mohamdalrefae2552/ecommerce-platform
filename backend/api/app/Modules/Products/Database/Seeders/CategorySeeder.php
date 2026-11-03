<?php

declare(strict_types=1);

namespace App\Modules\Products\Database\Seeders;

use App\Modules\Products\Models\Category;
use Illuminate\Database\Seeder;

/**
 * The fixed category list (GET /categories), in every environment. Idempotent.
 */
final class CategorySeeder extends Seeder
{
    private const array CATEGORIES = [
        'electronics' => 'إلكترونيات',
        'books' => 'كتب',
        'clothing' => 'ملابس',
        'home' => 'المنزل',
        'groceries' => 'مواد غذائية',
        'toys' => 'ألعاب',
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $slug => $name) {
            Category::query()->updateOrCreate(['slug' => $slug], ['name' => $name]);
        }
    }
}
