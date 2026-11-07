<?php

declare(strict_types=1);

use App\Console\Commands\PerfIndex;
use App\Modules\Products\Database\Seeders\CategorySeeder;
use App\Modules\Products\Models\Product;
use App\Modules\Users\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;
use function Pest\Laravel\seed;

/*
| Baseline mode of the live before/after demo (`make perf-compare`): each
| requirement 3 optimisation can be switched off and then behaves exactly
| as before it existed.
*/

beforeEach(function (): void {
    seed(CategorySeeder::class);
});

/**
 * Product/category queries run by the callback.
 */
function catalogueQueryCount(Closure $callback): int
{
    $count = 0;
    DB::listen(function (QueryExecuted $query) use (&$count): void {
        if (preg_match('/from "(products|categories)"/', $query->sql) === 1) {
            $count++;
        }
    });
    $callback();

    return $count;
}

it('reads the database every time and never touches the cache when the catalogue cache is off', function (): void {
    config(['catalog.cache_enabled' => false]);
    Cache::flush();
    $product = Product::factory()->create();
    $read = function () use ($product): void {
        getJson('/api/v1/categories')->assertOk();
        getJson('/api/v1/products')->assertOk()->assertJsonPath('data.0.id', $product->id);
        getJson('/api/v1/products/'.$product->id)->assertOk();
    };

    $first = catalogueQueryCount($read);
    $second = catalogueQueryCount($read);

    expect($first)->toBeGreaterThan(0)
        ->and($second)->toBe($first)
        ->and(Cache::has('catalog:version'))->toBeFalse();

    $product->update(['name' => 'Edited while off']);
    getJson('/api/v1/products/'.$product->id)->assertJsonPath('data.name', 'Edited while off');
    expect(Cache::has('catalog:version'))->toBeFalse();
});

it('never sends an ETag nor answers 304 when ETags are off', function (): void {
    config(['http.etag_enabled' => false]);
    $product = Product::factory()->create();

    foreach (['/api/v1/categories', '/api/v1/products', '/api/v1/products/'.$product->id] as $uri) {
        getJson($uri)->assertOk()->assertHeaderMissing('ETag');
        getJson($uri, ['If-None-Match' => '"'.hash('sha256', (string) getJson($uri)->getContent()).'"'])
            ->assertOk()
            ->assertHeaderMissing('ETag');
    }

    // Exactly as before requirement 3: a logged-in read is no-store again.
    getJson('/api/v1/products/'.$product->id, bearer(User::factory()->create()))
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('is optimised by default', function (): void {
    expect(config('catalog.cache_enabled'))->toBeTrue()
        ->and(config('http.etag_enabled'))->toBeTrue();
});

/**
 * Whether the requirement 3 orders index exists.
 */
function perfIndexExists(): bool
{
    return DB::scalar('select count(*) from pg_indexes where indexname = ?', [PerfIndex::INDEX]) === 1;
}

it('drops and recreates the orders index idempotently', function (): void {
    expect(perfIndexExists())->toBeTrue();

    expect(Artisan::call('app:perf-index', ['state' => 'off']))->toBe(0);
    expect(Artisan::call('app:perf-index', ['state' => 'off']))->toBe(0);
    expect(perfIndexExists())->toBeFalse();

    expect(Artisan::call('app:perf-index', ['state' => 'on']))->toBe(0);
    expect(Artisan::call('app:perf-index', ['state' => 'on']))->toBe(0);
    expect(perfIndexExists())->toBeTrue();
});

it('rejects an unknown state and refuses production', function (): void {
    expect(Artisan::call('app:perf-index', ['state' => 'maybe']))->toBe(1);

    app()->detectEnvironment(fn (): string => 'production');
    expect(Artisan::call('app:perf-index', ['state' => 'off']))->toBe(1)
        ->and(perfIndexExists())->toBeTrue();
});
