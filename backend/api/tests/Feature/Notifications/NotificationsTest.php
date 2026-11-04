<?php

declare(strict_types=1);

use App\Modules\Notifications\Mail\OrderUpdateMail;
use App\Modules\Notifications\Models\UserNotification;
use App\Modules\Orders\Contracts\Events\OrderPlaced;
use App\Modules\Orders\Models\Order;
use App\Modules\Products\Models\Product;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    Mail::fake();
});

it('notifies the customer once in the database and once by e-mail when an order is placed', function (): void {
    $customer = User::factory()->create();
    $product = Product::factory()->create();

    $orderId = postJson('/api/v1/orders', ['items' => [['product_id' => $product->id, 'quantity' => 1]]], bearer($customer))
        ->assertCreated()
        ->json('data.id');

    Mail::assertSent(OrderUpdateMail::class, 1);
    Mail::assertSent(OrderUpdateMail::class, fn (OrderUpdateMail $mail): bool => $mail->hasTo($customer->email));

    getJson('/api/v1/notifications', bearer($customer))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'order.placed')
        ->assertJsonPath('data.0.data.order_id', $orderId)
        ->assertJsonPath('data.0.read_at', null)
        ->assertMatchesContract();
});

it('notifies the customer of a status change', function (): void {
    $customer = User::factory()->create();
    $order = Order::factory()->ownedBy($customer)->create();

    patchJson('/api/v1/orders/'.$order->id.'/status', ['status' => 'confirmed'], bearer(User::factory()->admin()->create()))->assertOk();

    expect(UserNotification::query()->where('user_id', $customer->id)->sole()->type)->toBe('order.status_changed');
    Mail::assertSent(OrderUpdateMail::class, 1);
});

it('sends nothing when the transaction rolls back (afterCommit)', function (): void {
    try {
        DB::transaction(function (): void {
            event(new OrderPlaced('01JC41D4E5F6G7H8J9KAMBNCPQ', '01JC3Z8X7Q4M2N5P6R7S8T9V0W', 'pending'));

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect(UserNotification::query()->count())->toBe(0);
    Mail::assertNothingSent();
});

it('marks a notification read, idempotently, and hides other users\' notifications', function (): void {
    [$owner, $other] = [User::factory()->create(), User::factory()->create()];
    $notification = UserNotification::query()->create(['user_id' => $owner->id, 'type' => 'order.placed', 'order_id' => '01JC41D4E5F6G7H8J9KAMBNCPQ', 'order_status' => 'pending']);

    patchJson('/api/v1/notifications/'.$notification->id.'/read', [], bearer($other))
        ->assertNotFound()
        ->assertMatchesContract();

    $readAt = patchJson('/api/v1/notifications/'.$notification->id.'/read', [], bearer($owner))
        ->assertOk()
        ->assertMatchesContract()
        ->json('data.read_at');

    expect($readAt)->not->toBeNull();
    patchJson('/api/v1/notifications/'.$notification->id.'/read', [], bearer($owner))->assertOk()->assertJsonPath('data.read_at', $readAt);

    getJson('/api/v1/notifications?unread=true', bearer($owner))->assertOk()->assertJsonCount(0, 'data');
    getJson('/api/v1/notifications', bearer($other))->assertOk()->assertJsonCount(0, 'data');
});
