<?php

declare(strict_types=1);

use App\Modules\Orders\Domain\ReservationState;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\ReservationAttempt;
use App\Modules\Products\Models\Product;
use App\Modules\Users\Models\User;
use App\Shared\Inventory\Exceptions\InventoryTimeout;
use App\Shared\Inventory\Exceptions\InventoryUnavailable;
use App\Shared\Inventory\ReleaseReason;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\postJson;

/*
| Order-creation saga (docs/05 section 3): every row of the failure table.
*/

beforeEach(function (): void {
    Mail::fake();
});

/**
 * @return array{items: list<array{product_id: string, quantity: int}>}
 */
function orderOf(Product ...$products): array
{
    return ['items' => array_map(fn (Product $p): array => ['product_id' => $p->id, 'quantity' => 1], array_values($products))];
}

it('saves the order only after the inventory reserved every line (one call)', function (): void {
    $a = Product::factory()->create();
    $b = Product::factory()->create();
    inventory()->setStock($a->id, 1)->setStock($b->id, 1);

    $id = postJson('/api/v1/orders', orderOf($a, $b), bearer(User::factory()->create()))->assertCreated()->json('data.id');

    $attempt = ReservationAttempt::query()->sole();
    expect($attempt->state)->toBe(ReservationState::Confirmed)
        ->and($attempt->order_id)->toBe($id)
        ->and(Order::query()->sole()->reservation_id)->toBe($attempt->reservation_id)
        ->and(inventory()->reservations)->toHaveKey($attempt->reservation_id)
        ->and(inventory()->available)->toBe([$a->id => 0, $b->id => 0]);
});

it('refuses the whole order when one line is short (409, no order, nothing to release)', function (): void {
    $a = Product::factory()->create();
    $b = Product::factory()->create();
    inventory()->setStock($a->id, 5)->setStock($b->id, 0);

    postJson('/api/v1/orders', orderOf($a, $b), bearer(User::factory()->create()))
        ->assertConflict()
        ->assertJsonPath('code', 'INSUFFICIENT_STOCK')
        ->assertJsonPath('shortages', [['product_id' => $b->id, 'requested' => 1, 'available' => 0]])
        ->assertMatchesContract();

    expect(Order::query()->count())->toBe(0)
        ->and(ReservationAttempt::query()->sole()->state)->toBe(ReservationState::Rejected)
        ->and(inventory()->releases)->toBe([])
        ->and(inventory()->available[$a->id])->toBe(5);
});

it('gives the units back at once when the reservation outcome is unknown (503)', function (Throwable $failure, string $code): void {
    $product = Product::factory()->create();
    inventory()->setStock($product->id, 3)->failNext('reserve', $failure);

    postJson('/api/v1/orders', orderOf($product), bearer(User::factory()->create()))
        ->assertStatus(503)
        ->assertJsonPath('code', $code)
        ->assertHeader('Retry-After', '5')
        ->assertMatchesContract();

    $attempt = ReservationAttempt::query()->sole();
    expect(Order::query()->count())->toBe(0)
        ->and($attempt->state)->toBe(ReservationState::Released)
        ->and(inventory()->releases)->toBe([[$attempt->reservation_id, ReleaseReason::ReserveOutcomeUnknown]]);
})->with([
    'timeout' => [new InventoryTimeout, 'INVENTORY_TIMEOUT'],
    'unavailable' => [new InventoryUnavailable, 'INVENTORY_UNAVAILABLE'],
]);

it('leaves RELEASE_PENDING for the reconciler when the release fails too', function (): void {
    $product = Product::factory()->create();
    inventory()->setStock($product->id, 3)->failNext('reserve', new InventoryUnavailable)->failNext('release', new InventoryUnavailable);

    postJson('/api/v1/orders', orderOf($product), bearer(User::factory()->create()))->assertStatus(503);

    expect(ReservationAttempt::query()->sole()->state)->toBe(ReservationState::ReleasePending)
        ->and(Order::query()->count())->toBe(0);
});

it('releases the reservation when the order cannot be saved after it (R2-E1)', function (): void {
    $product = Product::factory()->create();
    inventory()->setStock($product->id, 1);
    // Make the order insert fail after a successful reservation.
    DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_test_failure CHECK (status <> 'pending')");

    postJson('/api/v1/orders', orderOf($product), bearer(User::factory()->create()))
        ->assertStatus(500)
        ->assertJsonPath('code', 'INTERNAL_ERROR')
        ->assertMatchesContract();

    $attempt = ReservationAttempt::query()->sole();
    expect(Order::query()->count())->toBe(0)
        ->and($attempt->state)->toBe(ReservationState::Released)
        ->and(inventory()->releases)->toBe([[$attempt->reservation_id, ReleaseReason::OrderPersistenceFailed]])
        ->and(inventory()->available[$product->id])->toBe(1);
});

it('uses a new reservation id for each attempt with the same Idempotency-Key', function (): void {
    $product = Product::factory()->create();
    $customer = User::factory()->create();
    $key = '7d1f2c3e-4b5a-4c6d-8e7f-901a2b3c4d5e';
    inventory()->setStock($product->id, 1)->failNext('reserve', new InventoryTimeout);

    postJson('/api/v1/orders', orderOf($product), bearer($customer) + ['Idempotency-Key' => $key])->assertStatus(503);
    postJson('/api/v1/orders', orderOf($product), bearer($customer) + ['Idempotency-Key' => $key])->assertCreated();

    expect(ReservationAttempt::query()->distinct()->count('reservation_id'))->toBe(2);
});
