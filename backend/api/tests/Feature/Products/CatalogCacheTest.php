<?php

declare(strict_types=1);

use App\Modules\Products\Cache\CatalogCache;
use App\Modules\Products\Database\Seeders\CategorySeeder;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Users\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\call;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\seed;
use function Pest\Laravel\travel;

/*
| Catalogue cache (requirement 3): categories, product pages and product
| details are read from the cache after the first request; the stock is
| never cached; every committed product or category write is visible on
| the very next read.
*/

beforeEach(function (): void {
    Storage::fake('public');
    seed(CategorySeeder::class);
});

/**
 * Catalogue tables touched by the callback's SQL.
 *
 * @return list<string>
 */
function catalogQueries(Closure $callback): array
{
    $tables = [];
    DB::listen(function (QueryExecuted $query) use (&$tables): void {
        if (preg_match('/from "(products|categories)"/', $query->sql, $match) === 1) {
            $tables[] = $match[1];
        }
    });

    $callback();

    return $tables;
}

it('serves categories, a page and a product from the cache on the second read', function (): void {
    $product = Product::factory()->create();

    $first = catalogQueries(function () use ($product): void {
        getJson('/api/v1/categories')->assertOk();
        getJson('/api/v1/products?category='.$product->category->slug)->assertOk();
        getJson('/api/v1/products/'.$product->id)->assertOk();
    });
    $second = catalogQueries(function () use ($product): void {
        getJson('/api/v1/categories')->assertOk()->assertMatchesContract();
        getJson('/api/v1/products?category='.$product->category->slug)->assertOk()->assertJsonPath('data.0.id', $product->id)->assertMatchesContract();
        getJson('/api/v1/products/'.$product->id)->assertOk()->assertJsonPath('data.id', $product->id)->assertMatchesContract();
    });

    expect($first)->not()->toBe([])
        ->and($second)->toBe([]);
});

it('never caches the stock: a cached product shows the current quantity', function (): void {
    $product = Product::factory()->create();
    inventory()->available[$product->id] = 5;

    getJson('/api/v1/products/'.$product->id)->assertJsonPath('data.stock.available', 5);
    getJson('/api/v1/products')->assertJsonPath('data.0.stock.available', 5);

    inventory()->available[$product->id] = 1;

    getJson('/api/v1/products/'.$product->id)->assertJsonPath('data.stock.available', 1);
    getJson('/api/v1/products')->assertJsonPath('data.0.stock.available', 1);
});

it('shows a product created by the admin in the next list read', function (): void {
    $admin = User::factory()->admin()->create();
    getJson('/api/v1/products')->assertJsonPath('meta.total', 0);

    call('POST', '/api/v1/products', [
        'name' => 'Brand new',
        'price_minor' => '1000',
        'category_id' => Category::query()->where('slug', 'books')->sole()->id,
    ], [], ['image' => UploadedFile::fake()->image('p.png', 10, 10)], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'multipart/form-data', 'HTTP_AUTHORIZATION' => 'Bearer '.jwtFor($admin)])
        ->assertCreated();

    getJson('/api/v1/products')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Brand new');
    getJson('/api/v1/products?category=books')->assertJsonPath('data.0.name', 'Brand new');
});

it('shows an admin edit on the next read of the details, every cached page and the category filter', function (): void {
    $admin = User::factory()->admin()->create();
    $product = Product::factory()->create(['name' => 'Before', 'price_minor' => 1000]);
    $slug = $product->category->slug;

    foreach (['/api/v1/products/'.$product->id, '/api/v1/products', '/api/v1/products?category='.$slug] as $uri) {
        getJson($uri)->assertOk();
    }

    patchJson('/api/v1/products/'.$product->id, ['name' => 'After', 'price_minor' => 2000, 'category_id' => Category::query()->where('slug', 'toys')->sole()->id], bearer($admin))->assertOk();

    getJson('/api/v1/products/'.$product->id)->assertJsonPath('data.name', 'After')->assertJsonPath('data.category.slug', 'toys');
    getJson('/api/v1/products')->assertJsonPath('data.0.price.amount_minor', 2000);
    getJson('/api/v1/products?category='.$slug)->assertJsonPath('meta.total', 0);
    getJson('/api/v1/products?category=toys')->assertJsonPath('data.0.name', 'After');
});

it('invalidates on any write path, not only the API', function (): void {
    $product = Product::factory()->create(['name' => 'Before']);
    getJson('/api/v1/products/'.$product->id)->assertJsonPath('data.name', 'Before');

    $product->update(['name' => 'Changed by a console fix']);
    getJson('/api/v1/products/'.$product->id)->assertJsonPath('data.name', 'Changed by a console fix');

    $product->delete();
    getJson('/api/v1/products/'.$product->id)->assertNotFound();
});

it('invalidates the category list when a category changes', function (): void {
    getJson('/api/v1/categories')->assertJsonCount(6, 'data');

    Category::factory()->create(['slug' => 'garden', 'name' => 'حديقة']);

    getJson('/api/v1/categories')->assertJsonCount(7, 'data');
    getJson('/api/v1/products?category=garden')->assertOk();
});

it('invalidates only after the transaction commits', function (): void {
    $product = Product::factory()->create(['name' => 'Before']);
    getJson('/api/v1/products/'.$product->id);
    $version = Cache::integer('catalog:version');

    DB::transaction(function () use ($product, $version): void {
        $product->update(['name' => 'Inside the transaction']);
        expect(Cache::integer('catalog:version'))->toBe($version);
    });

    expect(Cache::integer('catalog:version'))->toBeGreaterThan($version);
});

it('does not cache unknown products, name searches or deep pages', function (): void {
    Product::factory()->create(['name' => 'Searchable']);

    getJson('/api/v1/products/01JC0000000000000000000000')->assertNotFound();
    getJson('/api/v1/products?q=Search')->assertJsonPath('data.0.name', 'Searchable');
    getJson('/api/v1/products?page=51')->assertOk();

    $queries = catalogQueries(function (): void {
        getJson('/api/v1/products/01JC0000000000000000000000')->assertNotFound();
        getJson('/api/v1/products?q=Search')->assertOk();
        getJson('/api/v1/products?page=51')->assertOk();
    });

    expect($queries)->toContain('products');
});

it('restarts a lost version key at a value never used before', function (): void {
    $product = Product::factory()->create(['name' => 'Before']);
    getJson('/api/v1/products/'.$product->id);
    $cache = app(CatalogCache::class);
    $cache->invalidate();
    $used = Cache::integer('catalog:version');

    Cache::forget('catalog:version');
    travel(1)->seconds();
    $cache->invalidate();

    expect(Cache::integer('catalog:version'))->toBeGreaterThan($used);
});
