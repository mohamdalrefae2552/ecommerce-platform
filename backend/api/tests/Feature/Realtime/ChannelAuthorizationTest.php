<?php

declare(strict_types=1);

use App\Modules\Orders\Models\Order;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\postJson;

/*
| Requirement 4, "identity and permission are verified before subscribing
| to either channel": POST /api/v1/broadcasting/auth signs a subscription
| only for a user who may follow that channel.
*/

const SOCKET_ID = '271035419.403417282';

/**
 * @return TestResponse<Response>
 */
function subscribe(string $channel, ?User $user): TestResponse
{
    return postJson('/api/v1/broadcasting/auth', ['socket_id' => SOCKET_ID, 'channel_name' => $channel], $user === null ? [] : bearer($user));
}

function expectedSignature(string $channel): string
{
    $key = Config::string('broadcasting.connections.reverb.key');
    $secret = Config::string('broadcasting.connections.reverb.secret');

    return $key.':'.hash_hmac('sha256', SOCKET_ID.':'.$channel, $secret);
}

it('signs the subscription of the customer who owns the order', function (): void {
    $owner = User::factory()->create();
    $order = Order::factory()->ownedBy($owner)->create();
    $channel = 'private-orders.'.$order->id;

    subscribe($channel, $owner)
        ->assertOk()
        ->assertExactJson(['auth' => expectedSignature($channel)])
        ->assertMatchesContract();
});

it('refuses another customer who tries to follow the order (PROOF-R4: nobody else follows it)', function (): void {
    $order = Order::factory()->ownedBy(User::factory()->create())->create();

    subscribe('private-orders.'.$order->id, User::factory()->create())
        ->assertForbidden()
        ->assertJsonPath('code', 'FORBIDDEN')
        ->assertJsonMissingPath('auth')
        ->assertMatchesContract();
});

it('refuses an order that does not exist exactly like an order of someone else', function (): void {
    subscribe('private-orders.01JC41D4E5F6G7H8J9KAMBNCPQ', User::factory()->create())->assertForbidden();
});

it('does not let an admin follow a customer order channel: the dashboard channel is theirs', function (): void {
    $order = Order::factory()->ownedBy(User::factory()->create())->create();

    subscribe('private-orders.'.$order->id, User::factory()->admin()->create())->assertForbidden();
});

it('lets only an admin subscribe to the admin dashboard channel', function (): void {
    subscribe('private-admin.orders', User::factory()->admin()->create())
        ->assertOk()
        ->assertExactJson(['auth' => expectedSignature('private-admin.orders')])
        ->assertMatchesContract();

    subscribe('private-admin.orders', User::factory()->create())
        ->assertForbidden()
        ->assertMatchesContract();
});

it('refuses a subscription without a valid token', function (): void {
    subscribe('private-admin.orders', null)
        ->assertUnauthorized()
        ->assertMatchesContract();
});

it('refuses a channel no module registered', function (): void {
    subscribe('private-users.all', User::factory()->admin()->create())->assertForbidden();
});

it('validates the socket id and the channel name', function (array $body): void {
    postJson('/api/v1/broadcasting/auth', $body, bearer(User::factory()->admin()->create()))
        ->assertUnprocessable()
        ->assertMatchesContract();
})->with([
    'no socket id' => [['channel_name' => 'private-admin.orders']],
    'malformed socket id' => [['socket_id' => 'abc', 'channel_name' => 'private-admin.orders']],
    'socket id with an injected channel' => [['socket_id' => '1.2:private-admin.orders', 'channel_name' => 'private-admin.orders']],
    'public channel' => [['socket_id' => SOCKET_ID, 'channel_name' => 'admin.orders']],
    'presence channel' => [['socket_id' => SOCKET_ID, 'channel_name' => 'presence-admin.orders']],
]);
