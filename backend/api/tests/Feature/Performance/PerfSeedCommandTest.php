<?php

declare(strict_types=1);

use App\Console\Commands\PerfSeed;
use App\Modules\Orders\Models\Order;
use App\Modules\Products\Database\Seeders\CategorySeeder;
use App\Modules\Products\Models\Product;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\getJson;
use function Pest\Laravel\seed;

beforeEach(function (): void {
    Storage::fake('public');
    seed(CategorySeeder::class);
    User::factory()->admin()->create();
    User::factory()->customer()->count(3)->create();
});

it('bulk-inserts products and a consistent order history', function (): void {
    expect(Artisan::call('app:perf-seed', ['--orders' => 250, '--products' => 30]))->toBe(0);

    expect(Product::query()->where('description', PerfSeed::MARKER)->count())->toBe(30)
        ->and(Order::query()->count())->toBe(250)
        ->and(DB::table('order_items')->count())->toBeGreaterThanOrEqual(250);

    // One stored image shared by every generated product.
    expect(Product::query()->distinct()->count('image'))->toBe(1);
    Storage::disk('public')->assertExists('products/'.Product::query()->firstOrFail()->image);

    // Upper-case ULIDs, owners are customers, everything is in the past.
    $ids = Order::query()->pluck('id');
    expect($ids->every(fn (string $id): bool => preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id) === 1))->toBeTrue()
        ->and(Order::query()->whereNotIn('user_id', User::query()->where('role', 'customer')->select('id'))->count())->toBe(0)
        ->and(Order::query()->where('updated_at', '>', now())->count())->toBe(0);

    // Totals match the lines and the last history entry is the order status.
    $mismatched = DB::scalar(<<<'SQL'
        SELECT count(*) FROM orders o
        WHERE o.total_minor <> (SELECT sum(unit_price_minor * quantity) FROM order_items i WHERE i.order_id = o.id)
           OR o.status <> (SELECT h.to_status FROM order_status_history h WHERE h.order_id = o.id ORDER BY h.id DESC LIMIT 1)
           OR (SELECT h.from_status FROM order_status_history h WHERE h.order_id = o.id ORDER BY h.id LIMIT 1) IS NOT NULL
        SQL);
    expect($mismatched)->toBe(0);
});

it('produces orders the API serves to their owner', function (): void {
    Artisan::call('app:perf-seed', ['--orders' => 40, '--products' => 5]);
    $order = Order::query()->firstOrFail();
    $owner = User::query()->findOrFail($order->user_id);

    getJson('/api/v1/orders/'.$order->id, bearer($owner))->assertOk()->assertMatchesContract();
});

it('refuses a second run while generated data exists', function (): void {
    Artisan::call('app:perf-seed', ['--orders' => 10, '--products' => 3]);
    Artisan::call('app:perf-seed', ['--orders' => 10, '--products' => 3]);

    expect(Order::query()->count())->toBe(10)
        ->and(Product::query()->count())->toBe(3);
});

it('refuses to run in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    expect(Artisan::call('app:perf-seed', ['--orders' => 10, '--products' => 3]))->toBe(1)
        ->and(Product::query()->count())->toBe(0);
});

it('fails without an admin to sign the history', function (): void {
    User::query()->where('role', 'admin')->delete();

    expect(Artisan::call('app:perf-seed', ['--orders' => 10, '--products' => 3]))->toBe(1)
        ->and(Order::query()->count())->toBe(0);
});
