<?php

declare(strict_types=1);

use App\Modules\Notifications\Models\UserNotification;
use App\Modules\Orders\Models\Order;
use App\Modules\Products\Models\Category;
use App\Modules\Products\Models\Product;
use App\Modules\Users\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\getJson;

/*
| N+1 guard (requirement 3): a list endpoint runs the same number of SQL
| queries for 2 rows and for 20 rows. Lazy loading is also forbidden outside
| production (App\Shared\Database\DatabaseServiceProvider), so a relation
| read row by row throws in every test.
*/

/**
 * SQL statements run by one GET, with an empty cache.
 *
 * @param  array<string, string>  $headers
 */
function queriesFor(string $uri, array $headers = []): int
{
    Cache::flush();
    $count = 0;
    DB::listen(function () use (&$count): void {
        $count++;
    });

    getJson($uri, $headers)->assertOk();

    return $count;
}

it('forbids lazy loading outside production', function (): void {
    expect(Model::preventsLazyLoading())->toBeTrue();
});

it('lists products with a constant number of queries', function (): void {
    $category = Category::factory()->create();
    Product::factory()->count(2)->for($category)->create();
    $few = queriesFor('/api/v1/products?per_page=50');

    Product::factory()->count(18)->create();
    $many = queriesFor('/api/v1/products?per_page=50');

    expect($many)->toBe($few);
});

it('lists a customer\'s orders with a constant number of queries', function (): void {
    $customer = User::factory()->create();
    Order::factory()->count(2)->ownedBy($customer)->create();
    $few = queriesFor('/api/v1/orders?per_page=50', bearer($customer));

    Order::factory()->count(18)->ownedBy($customer)->create();
    $many = queriesFor('/api/v1/orders?per_page=50', bearer($customer));

    expect($many)->toBe($few);
});

it('lists every order for the admin with a constant number of queries', function (): void {
    $admin = User::factory()->admin()->create();
    Order::factory()->count(2)->create();
    $few = queriesFor('/api/v1/orders?per_page=50', bearer($admin));

    Order::factory()->count(18)->create();
    $many = queriesFor('/api/v1/orders?per_page=50', bearer($admin));

    expect($many)->toBe($few);
});

it('lists notifications with a constant number of queries', function (): void {
    $customer = User::factory()->create();
    $order = Order::factory()->ownedBy($customer)->create();
    $notify = fn (int $n) => collect(range(1, $n))->each(fn () => UserNotification::query()->create([
        'user_id' => $customer->id, 'type' => 'order.placed', 'order_id' => $order->id, 'order_status' => 'pending',
    ]));

    $notify(2);
    $few = queriesFor('/api/v1/notifications?per_page=50', bearer($customer));
    $notify(18);
    $many = queriesFor('/api/v1/notifications?per_page=50', bearer($customer));

    expect($many)->toBe($few);
});

it('shows an order with its lines and history in a constant number of queries', function (): void {
    $customer = User::factory()->create();
    $order = Order::factory()->ownedBy($customer)->create();
    $few = queriesFor('/api/v1/orders/'.$order->id, bearer($customer));

    foreach (range(1, 10) as $n) {
        $order->items()->create(['product_id' => (string) Str::ulid(), 'product_name' => 'Line '.$n, 'unit_price_minor' => 100, 'quantity' => 1]);
    }
    $many = queriesFor('/api/v1/orders/'.$order->id, bearer($customer));

    expect($many)->toBe($few);
});
