<?php

declare(strict_types=1);

use App\Modules\Products\Database\Seeders\CategorySeeder;
use App\Modules\Products\Models\Product;
use App\Shared\Inventory\Exceptions\InventoryUnavailable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\seed;

beforeEach(function (): void {
    Storage::fake('public');
    seed(CategorySeeder::class);
});

it('creates nine products with images and the initial stock', function (): void {
    expect(Artisan::call('app:demo-catalog'))->toBe(0);

    expect(Product::query()->count())->toBe(9);
    Product::query()->each(function (Product $product): void {
        Storage::disk('public')->assertExists('products/'.$product->image);
    });

    $book = Product::query()->where('name', 'كتاب «فن البرمجة»')->sole();
    $laptop = Product::query()->where('name', 'حاسوب محمول')->sole();
    expect($book->price_minor)->toBe(9_000_000)
        ->and($laptop->price_minor)->toBe(750_000_000)
        ->and(inventory()->available[$book->id])->toBe(1)
        ->and(inventory()->available[$laptop->id])->toBe(2)
        ->and(inventory()->adjustments)->toHaveCount(9)
        ->and(array_sum(array_column(inventory()->adjustments, 2)))->toBe(54);
});

it('adds nothing on a second run', function (): void {
    Artisan::call('app:demo-catalog');
    Artisan::call('app:demo-catalog');

    expect(Product::query()->count())->toBe(9)
        ->and(inventory()->adjustments)->toHaveCount(9);
});

it('refuses to run in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    expect(Artisan::call('app:demo-catalog'))->toBe(1)
        ->and(Product::query()->count())->toBe(0);
});

it('fails when the inventory does not accept the stock', function (): void {
    inventory()->failNext('adjust', new InventoryUnavailable('down'));

    expect(Artisan::call('app:demo-catalog'))->toBe(1)
        ->and(Product::query()->count())->toBe(9);
});
