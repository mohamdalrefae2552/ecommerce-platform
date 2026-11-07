<?php

declare(strict_types=1);

use App\Modules\Orders\Models\Order;
use App\Modules\Products\Models\Product;
use App\Modules\Users\Database\Factories\UserFactory;
use App\Modules\Users\Models\User;
use App\Shared\Http\Middleware\ReadYourWrites;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Mockery\MockInterface;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\travel;

/*
| Read replica with "the user sees what they just created" (requirement 3,
| R3-4, ADR-0014). The suite has one database, so the replica itself is
| proven on the running stack by `make replica-check`; here: the connection
| configuration, and the decision of ReadYourWrites (who reads the primary).
*/

beforeEach(function (): void {
    Mail::fake();
    inventory()->defaultAvailable = 100;
    // A replica as on the stack. The suite's connection is already open, so
    // this changes only the middleware's decision, not where SQL goes.
    config(['database.connections.pgsql.read.host' => ['db-app-replica']]);
});

it('does nothing when no replica is configured (read host = write host)', function (): void {
    config(['database.connections.pgsql.read.host' => config('database.connections.pgsql.write.host')]);
    $customer = User::factory()->create();
    $product = Product::factory()->create();

    postJson('/api/v1/orders', ['items' => [['product_id' => $product->id, 'quantity' => 1]]], bearer($customer))->assertCreated();

    expect(Cache::has('read-your-writes:user:'.$customer->id))->toBeFalse();
});

it('splits reads and writes and keeps a request that wrote on the primary (sticky)', function (): void {
    expect(config('database.connections.pgsql.sticky'))->toBeTrue()
        ->and(config('database.connections.pgsql.read.host'))->toBeArray()
        ->and(config('database.connections.pgsql.write.host'))->toBeArray()
        ->and(config('database.read_your_writes_seconds'))->toBe(5);
});

it('answers POST /orders with the order it just wrote, lines and history included', function (): void {
    $customer = User::factory()->create();
    $product = Product::factory()->create();

    postJson('/api/v1/orders', ['items' => [['product_id' => $product->id, 'quantity' => 1]]], bearer($customer))
        ->assertCreated()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.status_history.0.to', 'pending');
});

it('opens a read-your-writes window for the user after a write, and only then', function (): void {
    $customer = User::factory()->create();
    $product = Product::factory()->create();
    $key = 'read-your-writes:user:'.$customer->id;

    getJson('/api/v1/orders', bearer($customer))->assertOk();
    expect(Cache::has($key))->toBeFalse();

    postJson('/api/v1/orders', ['items' => [['product_id' => $product->id, 'quantity' => 1]]], bearer($customer))->assertCreated();
    $id = Order::query()->sole()->id;
    expect(Cache::has($key))->toBeTrue()
        ->and(Cache::has('read-your-writes:user:'.User::factory()->create()->id))->toBeFalse();

    getJson('/api/v1/orders/'.$id, bearer($customer))->assertOk();
    getJson('/api/v1/orders', bearer($customer))->assertJsonPath('data.0.id', $id);

    travel(6)->seconds();
    expect(Cache::has($key))->toBeFalse();
});

it('keys anonymous writes (login) by IP', function (): void {
    $user = User::factory()->create();

    postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD])->assertOk();

    expect(Cache::has('read-your-writes:ip:127.0.0.1'))->toBeTrue();
});

/**
 * Runs the middleware with a connection double; returns the read routing
 * it asked for during the request ([true] = primary).
 *
 * @param  Closure(): void  $controller
 * @return list<bool|string>
 */
function routing(bool $inWindow, Closure $controller): array
{
    $calls = [];
    /** @var Connection&MockInterface $connection */
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('forgetRecordModificationState')->once();
    $connection->shouldReceive('useWriteConnectionWhenReading')->andReturnUsing(function (bool $value) use (&$calls, $connection): Connection {
        $calls[] = $value;

        return $connection;
    });
    $connection->shouldReceive('hasModifiedRecords')->andReturn(false);
    /** @var DatabaseManager&MockInterface $db */
    $db = Mockery::mock(DatabaseManager::class);
    $db->shouldReceive('connection')->andReturn($connection);

    $cache = app(Repository::class);
    $inWindow
        ? $cache->put('read-your-writes:ip:10.0.0.9', true, 5)
        : $cache->forget('read-your-writes:ip:10.0.0.9');

    $request = Request::create('/api/v1/orders');
    $request->server->set('REMOTE_ADDR', '10.0.0.9');

    try {
        (new ReadYourWrites($db, $cache))->handle($request, function () use ($controller): Response {
            $controller();

            return new Response;
        });
    } catch (RuntimeException) {
        $calls[] = 'thrown';
    }

    return $calls;
}

it('reads from the primary inside the window and from the replica otherwise', function (): void {
    expect(routing(true, fn () => null))->toBe([true, false])
        ->and(routing(false, fn () => null))->toBe([false, false]);
});

it('restores replica reads even when the controller throws', function (): void {
    expect(routing(true, function (): void {
        throw new RuntimeException('boom');
    }))->toBe([true, false, 'thrown']);
});
