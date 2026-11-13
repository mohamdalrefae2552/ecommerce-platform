<?php

declare(strict_types=1);

use App\Modules\Orders\Models\Order;
use App\Modules\Products\Models\Product;
use App\Modules\Users\Models\User;
use App\Shared\Messaging\Outbox\Outbox;
use App\Shared\Messaging\Outbox\OutboxMessage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\travel;

/*
| Requirement 4, "the event is published and the application answers
| without waiting", with no event ever lost (transactional outbox, ADR-0015).
*/

beforeEach(function (): void {
    inventory()->defaultAvailable = 100;
});

function placeOrder(User $customer): string
{
    $product = Product::factory()->create();

    $id = postJson('/api/v1/orders', ['items' => [['product_id' => $product->id, 'quantity' => 2]]], bearer($customer))
        ->assertCreated()
        ->json('data.id');
    assert(is_string($id));

    return $id;
}

it('publishes order.placed after the order commits, with the documented payload', function (): void {
    $customer = User::factory()->create();

    $orderId = placeOrder($customer);

    expect(broker()->types())->toBe(['order.placed']);

    $message = broker()->published[0];
    $row = OutboxMessage::query()->sole();

    expect($message->id)->toBe($row->id)
        ->and($row->published_at)->not->toBeNull()
        ->and($message->payload)->toMatchArray([
            'order_id' => $orderId,
            'user_id' => $customer->id,
            'status' => 'pending',
            'currency' => 'SYP',
            'item_count' => 1,
        ])
        ->and($message->payload['total_minor'])->toBeInt()
        ->and($message->payload['created_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
});

it('publishes order.status_changed with the old and the new status', function (): void {
    $customer = User::factory()->create();
    $order = Order::factory()->ownedBy($customer)->create();

    patchJson('/api/v1/orders/'.$order->id.'/status', ['status' => 'confirmed'], bearer(User::factory()->admin()->create()))->assertOk();

    expect(broker()->types())->toBe(['order.status_changed'])
        ->and(broker()->published[0]->payload)->toMatchArray([
            'order_id' => $order->id,
            'user_id' => $customer->id,
            'from' => 'pending',
            'to' => 'confirmed',
        ]);
});

it('still creates the order when the broker is down, and keeps the event for the relay', function (): void {
    broker()->down = true;

    placeOrder(User::factory()->create());

    expect(broker()->published)->toBe([])
        ->and(OutboxMessage::query()->sole()->published_at)->toBeNull();
});

it('relays pending events once the broker is back, oldest first, exactly the pending ones', function (): void {
    $customer = User::factory()->create();
    broker()->down = true;
    $first = placeOrder($customer);
    $second = placeOrder($customer);

    // The broker is still down: the relay fails and nothing is marked.
    travel(10)->seconds();
    expect(Artisan::call('outbox:relay'))->toBe(1);
    expect(OutboxMessage::query()->whereNull('published_at')->count())->toBe(2);

    broker()->down = false;
    expect(Artisan::call('outbox:relay'))->toBe(0);

    expect(array_map(fn ($message): mixed => $message->payload['order_id'], broker()->published))->toBe([$first, $second])
        ->and(OutboxMessage::query()->whereNull('published_at')->count())->toBe(0);

    // Nothing left: a second run publishes nothing again.
    expect(Artisan::call('outbox:relay'))->toBe(0);
    expect(broker()->published)->toHaveCount(2);
});

it('does not relay an event the request is still about to publish itself', function (): void {
    broker()->down = true;
    placeOrder(User::factory()->create());
    broker()->down = false;

    // Younger than relay_after_seconds: left alone.
    expect(Artisan::call('outbox:relay'))->toBe(0);

    expect(broker()->published)->toBe([]);
});

it('records nothing and publishes nothing when the business transaction rolls back', function (): void {
    try {
        DB::transaction(function (): void {
            app(Outbox::class)->record('order.placed', ['order_id' => '01JC41D4E5F6G7H8J9KAMBNCPQ']);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(OutboxMessage::query()->count())->toBe(0)
        ->and(broker()->published)->toBe([]);
});

it('prunes published rows after the retention period only', function (): void {
    placeOrder(User::factory()->create());

    travel(23)->hours();
    expect(Artisan::call('outbox:relay'))->toBe(0);
    expect(OutboxMessage::query()->count())->toBe(1);

    travel(2)->hours();
    expect(Artisan::call('outbox:relay'))->toBe(0);
    expect(OutboxMessage::query()->count())->toBe(0);
});
