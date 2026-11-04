<?php

declare(strict_types=1);

use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Products\Models\Product;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    Mail::fake();
    inventory()->defaultAvailable = 100;
});

it('creates an order with a price snapshot, 201 and Location', function (): void {
    $customer = User::factory()->create();
    $phone = Product::factory()->create(['name' => 'Phone', 'price_minor' => 450000]);
    $case = Product::factory()->create(['name' => 'Case', 'price_minor' => 1000]);

    $response = postJson('/api/v1/orders', ['items' => [
        ['product_id' => $phone->id, 'quantity' => 2],
        ['product_id' => $case->id, 'quantity' => 1],
    ]], bearer($customer))
        ->assertCreated()
        ->assertJsonPath('data.user_id', $customer->id)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.total.amount_minor', 901000)
        ->assertJsonPath('data.items.0.product_name', 'Phone')
        ->assertJsonPath('data.items.0.line_total.amount_minor', 900000)
        ->assertJsonPath('data.status_history.0.from', null)
        ->assertJsonPath('data.status_history.0.actor_role', 'customer')
        ->assertMatchesContract();

    $order = Order::query()->sole();
    $response->assertHeader('Location', url('/api/v1/orders/'.$order->id));

    $phone->update(['price_minor' => 1]);
    getJson('/api/v1/orders/'.$order->id, bearer($customer))->assertJsonPath('data.items.0.unit_price.amount_minor', 450000);
});

it('rejects unknown and duplicated products with 422 on the line', function (Closure $items, string $field): void {
    $product = Product::factory()->create();

    postJson('/api/v1/orders', ['items' => $items($product)], bearer(User::factory()->create()))
        ->assertUnprocessable()
        ->assertJsonStructure(['errors' => [$field]])
        ->assertMatchesContract();

    expect(Order::query()->count())->toBe(0);
})->with([
    'unknown product' => [fn (Product $p): array => [['product_id' => '01JC40A1B2C3D4E5F6G7H8J9KM', 'quantity' => 1]], 'items.0.product_id'],
    'duplicated product' => [fn (Product $p): array => [['product_id' => $p->id, 'quantity' => 1], ['product_id' => $p->id, 'quantity' => 2]], 'items.1.product_id'],
    'quantity above 10' => [fn (Product $p): array => [['product_id' => $p->id, 'quantity' => 11]], 'items.0.quantity'],
    'no lines' => [fn (Product $p): array => [], 'items'],
    'unknown line field' => [fn (Product $p): array => [['product_id' => $p->id, 'quantity' => 1, 'price_minor' => 1]], 'items.0'],
]);

it('replays the same Idempotency-Key and body as the same order (200)', function (): void {
    $customer = User::factory()->create();
    $product = Product::factory()->create();
    $key = (string) Str::uuid();
    $body = ['items' => [['product_id' => $product->id, 'quantity' => 1]]];

    $first = postJson('/api/v1/orders', $body, bearer($customer) + ['Idempotency-Key' => $key])->assertCreated();
    postJson('/api/v1/orders', $body, bearer($customer) + ['Idempotency-Key' => $key])
        ->assertOk()
        ->assertJsonPath('data.id', $first->json('data.id'))
        ->assertMatchesContract();

    expect(Order::query()->count())->toBe(1);
});

it('refuses the same Idempotency-Key with another body (422)', function (): void {
    $customer = User::factory()->create();
    $product = Product::factory()->create();
    $key = (string) Str::uuid();

    postJson('/api/v1/orders', ['items' => [['product_id' => $product->id, 'quantity' => 1]]], bearer($customer) + ['Idempotency-Key' => $key])->assertCreated();
    postJson('/api/v1/orders', ['items' => [['product_id' => $product->id, 'quantity' => 2]]], bearer($customer) + ['Idempotency-Key' => $key])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED')
        ->assertMatchesContract();
});

it('answers a malformed Idempotency-Key with 400', function (): void {
    $product = Product::factory()->create();

    postJson('/api/v1/orders', ['items' => [['product_id' => $product->id, 'quantity' => 1]]], bearer(User::factory()->create()) + ['Idempotency-Key' => 'not-a-uuid'])
        ->assertStatus(400)
        ->assertJsonPath('code', 'MALFORMED_REQUEST')
        ->assertMatchesContract();
});

it('lists only the caller\'s orders, and the admin\'s user filter is refused to customers', function (): void {
    [$a, $b] = [User::factory()->create(), User::factory()->create()];
    $own = Order::factory()->ownedBy($b)->create();
    Order::factory()->ownedBy($a)->count(2)->create();

    getJson('/api/v1/orders', bearer($b))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $own->id)
        ->assertJsonPath('data.0.item_count', 1)
        ->assertMatchesContract();

    getJson('/api/v1/orders?user_id='.$a->id, bearer($b))
        ->assertUnprocessable()
        ->assertJsonStructure(['errors' => ['user_id']])
        ->assertMatchesContract();

    getJson('/api/v1/orders?user_id='.$a->id.'&status=pending', bearer(User::factory()->admin()->create()))
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('lets the admin move an order along the state machine with an audited history', function (): void {
    $order = Order::factory()->create();
    $admin = User::factory()->admin()->create();

    patchJson('/api/v1/orders/'.$order->id.'/status', ['status' => 'confirmed', 'note' => 'payment received'], bearer($admin))
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmed')
        ->assertJsonPath('data.status_history.1.from', 'pending')
        ->assertJsonPath('data.status_history.1.to', 'confirmed')
        ->assertJsonPath('data.status_history.1.actor_role', 'admin')
        ->assertJsonPath('data.status_history.1.note', 'payment received')
        ->assertJsonMissingPath('data.status_history.1.actor_id')
        ->assertMatchesContract();

    expect($order->history()->reorder('id', 'desc')->value('actor_id'))->toBe($admin->id);
});

it('refuses every transition outside the state machine with 409', function (OrderStatus $from, OrderStatus $to): void {
    $order = Order::factory()->status($from)->create();

    patchJson('/api/v1/orders/'.$order->id.'/status', ['status' => $to->value], bearer(User::factory()->admin()->create()))
        ->assertConflict()
        ->assertJsonPath('code', 'ORDER_INVALID_TRANSITION')
        ->assertMatchesContract();

    expect($order->refresh()->status)->toBe($from);
})->with(function (): Generator {
    foreach (OrderStatus::cases() as $from) {
        foreach (OrderStatus::cases() as $to) {
            if (! $from->canTransitionTo($to)) {
                yield $from->value.' → '.$to->value => [$from, $to];
            }
        }
    }
});

it('answers an unknown order with 404 for the admin', function (): void {
    patchJson('/api/v1/orders/01JC41D4E5F6G7H8J9KAMBNCPQ/status', ['status' => 'confirmed'], bearer(User::factory()->admin()->create()))
        ->assertNotFound()
        ->assertMatchesContract();
});
