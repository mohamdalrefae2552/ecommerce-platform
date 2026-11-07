<?php

declare(strict_types=1);

use App\Modules\Orders\Models\Order;
use App\Modules\Users\Models\User;
use Illuminate\Support\Facades\Artisan;

use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;

/*
| app:perf-tokens (live before/after demo): tokens for the dev admin and
| customer1..N without HTTP logins. They are ordinary access tokens: the
| guard, the policies and the per-user rate limits still apply.
*/

beforeEach(function (): void {
    config(['users.dev_admin.email' => 'admin@example.test']);
    User::factory()->admin()->create(['email' => 'admin@example.test']);
    foreach (range(1, 3) as $n) {
        User::factory()->customer()->create(['email' => 'customer'.$n.'@example.test']);
    }
});

/**
 * @return array{admin: string, customers: list<string>}
 */
function perfTokens(int $count): array
{
    expect(Artisan::call('app:perf-tokens', ['count' => $count]))->toBe(0);

    /** @var array{admin: string, customers: list<string>} $tokens */
    $tokens = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    return $tokens;
}

it('prints working tokens for the admin and customer1..N in order', function (): void {
    $tokens = perfTokens(3);

    expect($tokens['customers'])->toHaveCount(3);
    getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$tokens['admin']])->assertOk()->assertJsonPath('data.role', 'admin');
    foreach ($tokens['customers'] as $i => $token) {
        getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.email', 'customer'.($i + 1).'@example.test');
    }
});

it('issues ordinary tokens: a customer token is still refused admin actions', function (): void {
    $tokens = perfTokens(1);
    $order = Order::factory()->ownedBy(User::query()->where('email', 'customer1@example.test')->sole())->create();

    patchJson('/api/v1/orders/'.$order->id.'/status', ['status' => 'confirmed'], ['Authorization' => 'Bearer '.$tokens['customers'][0]])
        ->assertForbidden();
});

it('fails when a seeded customer is missing', function (): void {
    expect(Artisan::call('app:perf-tokens', ['count' => 4]))->toBe(1);
});

it('refuses to run in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    expect(Artisan::call('app:perf-tokens', ['count' => 1]))->toBe(1)
        ->and(Artisan::output())->not()->toContain('ey');
});
