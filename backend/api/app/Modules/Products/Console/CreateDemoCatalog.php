<?php

declare(strict_types=1);

namespace App\Modules\Products\Console;

use App\Modules\Products\Actions\AddProduct;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Stock\InitialStock;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;

/**
 * Non-production helper for the interview: creates a realistic catalogue
 * through the same path as POST /products (AddProduct for the row and the
 * WebP image, InitialStock for the quantity in the inventory service).
 * Idempotent: a product whose name already exists is skipped.
 */
final class CreateDemoCatalog extends Command
{
    /**
     * Price is in minor units (SYP x 100).
     *
     * @var list<array{name: string, description: string, category: string, price_minor: int, stock: int, photo: string}>
     */
    private const array PRODUCTS = [
        ['name' => 'سماعات لاسلكية', 'description' => 'سماعات رأس لاسلكية بعزل ضجيج وبطارية تدوم طوال اليوم.', 'category' => 'electronics', 'price_minor' => 45_000_000, 'stock' => 8, 'photo' => 'headphones'],
        ['name' => 'ساعة يد كلاسيكية', 'description' => 'ساعة يد أنيقة بتصميم كلاسيكي وسوار جلدي مريح.', 'category' => 'clothing', 'price_minor' => 85_000_000, 'stock' => 5, 'photo' => 'watch'],
        ['name' => 'حذاء رياضي أحمر', 'description' => 'حذاء رياضي خفيف بنعل مرن يناسب الجري والاستخدام اليومي.', 'category' => 'clothing', 'price_minor' => 60_000_000, 'stock' => 10, 'photo' => 'sneakers'],
        ['name' => 'كاميرا فورية', 'description' => 'كاميرا فورية تطبع صورك في لحظات بألوان زاهية.', 'category' => 'electronics', 'price_minor' => 120_000_000, 'stock' => 3, 'photo' => 'camera'],
        ['name' => 'نظارة شمسية', 'description' => 'نظارة شمسية بعدسات تحمي من الأشعة فوق البنفسجية.', 'category' => 'clothing', 'price_minor' => 25_000_000, 'stock' => 12, 'photo' => 'sunglasses'],
        ['name' => 'عطر فاخر', 'description' => 'عطر فاخر برائحة شرقية دافئة تدوم لساعات طويلة.', 'category' => 'home', 'price_minor' => 38_000_000, 'stock' => 6, 'photo' => 'perfume'],
        ['name' => 'حاسوب محمول', 'description' => 'حاسوب محمول خفيف بأداء عالٍ مناسب للدراسة والعمل.', 'category' => 'electronics', 'price_minor' => 750_000_000, 'stock' => 2, 'photo' => 'laptop'],
        ['name' => 'حقيبة ظهر', 'description' => 'حقيبة ظهر متينة بجيوب متعددة ومساحة تتسع للحاسوب المحمول.', 'category' => 'clothing', 'price_minor' => 32_000_000, 'stock' => 7, 'photo' => 'backpack'],
        ['name' => 'كتاب «فن البرمجة»', 'description' => 'كتاب يشرح أساسيات البرمجة وأساليب كتابة الشيفرة النظيفة.', 'category' => 'books', 'price_minor' => 9_000_000, 'stock' => 1, 'photo' => 'book'],
    ];

    protected $signature = 'app:demo-catalog';

    protected $description = 'Create a realistic demo catalogue with photos and stock (non-production only, idempotent)';

    public function handle(AddProduct $add, InitialStock $initialStock): int
    {
        if ($this->laravel->environment('production')) {
            $this->components->error('app:demo-catalog is refused in production.');

            return self::FAILURE;
        }

        $categories = [];
        foreach (Category::query()->get() as $category) {
            $categories[$category->slug] = $category->id;
        }
        $failed = false;

        foreach (self::PRODUCTS as $row) {
            if (Product::query()->where('name', $row['name'])->exists()) {
                $this->components->twoColumnDetail($row['name'], 'skipped (exists)');

                continue;
            }

            $categoryId = $categories[$row['category']] ?? null;
            $path = base_path('database/demo-catalog/'.$row['photo'].'.jpg');

            if ($categoryId === null || ! is_file($path)) {
                $this->components->error('Missing category or photo for "'.$row['name'].'"; run the seeders first.');

                return self::FAILURE;
            }

            $product = $add->handle(
                $row['name'],
                $row['description'],
                $row['price_minor'],
                $categoryId,
                new UploadedFile($path, $row['photo'].'.jpg', 'image/jpeg', null, true),
            );

            $stock = $initialStock->register($product->id, $row['stock']);

            if ($stock->degraded) {
                $failed = true;
                $this->components->warn('"'.$row['name'].'" created but the inventory did not accept its stock.');

                continue;
            }

            $this->components->twoColumnDetail($row['name'], 'created, stock '.$row['stock']);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
