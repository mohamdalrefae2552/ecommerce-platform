<?php

declare(strict_types=1);

use App\Modules\Notifications\Models\UserNotification;
use App\Modules\Orders\Models\Order;
use App\Modules\Products\Models\Product;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\Support\OpenApiContract;

use function Pest\Laravel\json;

/*
| Authorization matrix of docs/02 section 3: every endpoint × every caller.
| "owner" is customer A who owns the order and the notification; "other" is
| customer B (PROOF-R1-1: B reading A's order ⇒ 404; PROOF-R1-2: a customer
| changing a status ⇒ 403). Bodies are valid where the role may act, so the
| status shows the authorization decision. A second test fails when a route
| is added without a row here.
*/

/**
 * method, uri, body ⇒ expected status for guest, owner, other, admin.
 *
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: array{guest: int, owner: int, other: int, admin: int}}>
 */
function authorizationMatrix(): array
{
    $line = ['items' => [['product_id' => '{product}', 'quantity' => 1]]];

    return [
        'register' => ['POST', '/api/v1/auth/register', [], ['guest' => 422, 'owner' => 422, 'other' => 422, 'admin' => 422]],
        'login' => ['POST', '/api/v1/auth/login', [], ['guest' => 422, 'owner' => 422, 'other' => 422, 'admin' => 422]],
        'refresh (CSRF guard)' => ['POST', '/api/v1/auth/refresh', [], ['guest' => 403, 'owner' => 403, 'other' => 403, 'admin' => 403]],
        'logout' => ['POST', '/api/v1/auth/logout', [], ['guest' => 401, 'owner' => 204, 'other' => 204, 'admin' => 204]],
        'me' => ['GET', '/api/v1/auth/me', [], ['guest' => 401, 'owner' => 200, 'other' => 200, 'admin' => 200]],
        'health' => ['GET', '/api/v1/health', [], ['guest' => 200, 'owner' => 200, 'other' => 200, 'admin' => 200]],
        'categories' => ['GET', '/api/v1/categories', [], ['guest' => 200, 'owner' => 200, 'other' => 200, 'admin' => 200]],
        'products' => ['GET', '/api/v1/products', [], ['guest' => 200, 'owner' => 200, 'other' => 200, 'admin' => 200]],
        'product' => ['GET', '/api/v1/products/{product}', [], ['guest' => 200, 'owner' => 200, 'other' => 200, 'admin' => 200]],
        'adjust stock (role checked before the body)' => ['PUT', '/api/v1/products/{product}/stock', [], ['guest' => 401, 'owner' => 403, 'other' => 403, 'admin' => 400]],
        'update product (role checked before the body)' => ['PATCH', '/api/v1/products/{product}', [], ['guest' => 401, 'owner' => 403, 'other' => 403, 'admin' => 422]],
        'create product (role checked before the body)' => ['POST', '/api/v1/products', [], ['guest' => 401, 'owner' => 403, 'other' => 403, 'admin' => 422]],
        'create order' => ['POST', '/api/v1/orders', $line, ['guest' => 401, 'owner' => 201, 'other' => 201, 'admin' => 403]],
        'list orders' => ['GET', '/api/v1/orders', [], ['guest' => 401, 'owner' => 200, 'other' => 200, 'admin' => 200]],
        'show order (PROOF-R1-1)' => ['GET', '/api/v1/orders/{order}', [], ['guest' => 401, 'owner' => 200, 'other' => 404, 'admin' => 200]],
        'change status (PROOF-R1-2)' => ['PATCH', '/api/v1/orders/{order}/status', ['status' => 'confirmed'], ['guest' => 401, 'owner' => 403, 'other' => 403, 'admin' => 200]],
        'notifications' => ['GET', '/api/v1/notifications', [], ['guest' => 401, 'owner' => 200, 'other' => 200, 'admin' => 200]],
        'read notification' => ['PATCH', '/api/v1/notifications/{notification}/read', [], ['guest' => 401, 'owner' => 200, 'other' => 404, 'admin' => 404]],
    ];
}

dataset('authorization', function (): Generator {
    foreach (authorizationMatrix() as $name => [$method, $uri, $body, $expected]) {
        foreach ($expected as $caller => $status) {
            yield $name.' as '.$caller => [$method, $uri, $body, $caller, $status];
        }
    }
});

it('enforces the authorization matrix', function (string $method, string $uri, array $body, string $caller, int $status): void {
    Mail::fake();
    inventory()->defaultAvailable = 100;
    $owner = User::factory()->create();
    $callers = ['guest' => null, 'owner' => $owner, 'other' => User::factory()->create(), 'admin' => User::factory()->admin()->create()];
    $product = Product::factory()->create();
    $order = Order::factory()->ownedBy($owner)->create();
    $notification = UserNotification::query()->create(['user_id' => $owner->id, 'type' => 'order.placed', 'order_id' => $order->id, 'order_status' => 'pending']);

    $replace = ['{product}' => $product->id, '{order}' => $order->id, '{notification}' => $notification->id];
    $uri = strtr($uri, $replace);
    $body = json_decode(strtr((string) json_encode($body), $replace), true);
    $user = $callers[$caller];

    json($method, $uri, is_array($body) ? $body : [], $user === null ? [] : bearer($user))
        ->assertStatus($status)
        ->assertMatchesContract();
})->with('authorization');

it('covers every registered route', function (): void {
    $covered = array_map(
        static fn (array $row): string => OpenApiContract::operation($row[0], $row[1]),
        array_values(authorizationMatrix()),
    );

    foreach (Route::getRoutes()->getRoutes() as $route) {
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            expect($covered)->toContain(OpenApiContract::operation($method, $route->uri()));
        }
    }
});
