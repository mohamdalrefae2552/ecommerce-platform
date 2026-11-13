<?php

declare(strict_types=1);

use App\Modules\Notifications\Mail\OrderUpdateMail;
use App\Modules\Notifications\Messaging\SendOrderNotification;
use App\Modules\Notifications\Models\UserNotification;
use App\Modules\Orders\Models\Order;
use App\Modules\Products\Models\Product;
use App\Modules\Users\Contracts\UserDirectory;
use App\Modules\Users\Models\User;
use App\Shared\Messaging\InvalidMessage;
use App\Shared\Messaging\Message;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;
use Mockery\MockInterface;

use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
| Requirement 4: notifications are produced by the `notifications.mail`
| worker from order events. Here the in-memory broker delivers each event
| to the worker's handler at once.
*/

beforeEach(function (): void {
    Mail::fake();
    inventory()->defaultAvailable = 100;
    broker()->deliver = true;
});

function orderPlacedMessage(User $customer, string $id = '01JC43A1B2C3D4E5F6G7H8J9KA'): Message
{
    return new Message($id, 'order.placed', [
        'order_id' => '01JC41D4E5F6G7H8J9KAMBNCPQ',
        'user_id' => $customer->id,
        'status' => 'pending',
        'total_minor' => 1000,
        'currency' => 'SYP',
        'item_count' => 1,
        'created_at' => '2026-11-13T10:00:00Z',
    ], '2026-11-13T10:00:00Z');
}

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

    $notification = UserNotification::query()->where('user_id', $customer->id)->sole();
    expect($notification->type)->toBe('order.status_changed')
        ->and($notification->order_status)->toBe('confirmed')
        ->and($notification->mailed_at)->not->toBeNull();
    Mail::assertSent(OrderUpdateMail::class, 1);
});

it('handles a message delivered twice only once (idempotent worker)', function (): void {
    $customer = User::factory()->create();
    $handler = app(SendOrderNotification::class);
    $message = orderPlacedMessage($customer);

    $handler->handle($message);
    $handler->handle($message);

    expect(UserNotification::query()->count())->toBe(1);
    Mail::assertSent(OrderUpdateMail::class, 1);
});

it('keeps the e-mail owed when the mail server fails, and sends it on the next attempt', function (): void {
    $customer = User::factory()->create();
    $message = orderPlacedMessage($customer);

    /** @var Mailer&MockInterface $failing */
    $failing = Mockery::mock(Mailer::class);
    $failing->shouldReceive('to')->andThrow(new RuntimeException('smtp down'));

    expect(fn () => (new SendOrderNotification(app(UserDirectory::class), $failing))->handle($message))
        ->toThrow(RuntimeException::class, 'smtp down');

    // The in-app notification exists, the e-mail is still owed.
    expect(UserNotification::query()->sole()->mailed_at)->toBeNull();
    Mail::assertNothingSent();

    app(SendOrderNotification::class)->handle($message);

    expect(UserNotification::query()->sole()->mailed_at)->not->toBeNull();
    Mail::assertSent(OrderUpdateMail::class, 1);
});

it('refuses a message it can never handle, so the worker parks it instead of retrying', function (): void {
    $handler = app(SendOrderNotification::class);

    expect(fn () => $handler->handle(new Message('01JC43A1B2C3D4E5F6G7H8J9KB', 'order.exploded', [], '2026-11-13T10:00:00Z')))
        ->toThrow(InvalidMessage::class);
    expect(fn () => $handler->handle(new Message('01JC43A1B2C3D4E5F6G7H8J9KC', 'order.placed', ['status' => 'pending'], '2026-11-13T10:00:00Z')))
        ->toThrow(InvalidMessage::class);

    expect(UserNotification::query()->count())->toBe(0);
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
