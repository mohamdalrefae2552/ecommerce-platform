<?php

declare(strict_types=1);

use App\Modules\Users\Database\Seeders\CustomerSeeder;
use App\Modules\Users\Models\User;
use App\Shared\Auth\JwtKeyStore;
use App\Shared\Auth\Role;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

use function Pest\Laravel\getJson;
use function Pest\Laravel\seed;

it('creates or updates the development admin from the environment (D-05)', function (): void {
    config(['users.dev_admin.email' => 'Admin@Example.test', 'users.dev_admin.password' => 'dev-admin-password-1']);
    User::factory()->create(['email' => 'admin@example.test']);

    expect(Artisan::call('app:create-admin', ['--dev' => true]))->toBe(0);

    $admin = User::query()->where('email', 'admin@example.test')->sole();
    expect($admin->role)->toBe(Role::Admin)
        ->and(Hash::check('dev-admin-password-1', $admin->password))->toBeTrue();
});

it('refuses the development admin in production', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    config(['users.dev_admin.email' => 'admin@example.test', 'users.dev_admin.password' => 'dev-admin-password-1']);

    expect(Artisan::call('app:create-admin', ['--dev' => true]))->toBe(1);

    expect(User::query()->count())->toBe(0);
});

it('rejects a weak admin password', function (): void {
    config(['users.dev_admin.email' => 'admin@example.test', 'users.dev_admin.password' => 'short']);

    expect(Artisan::call('app:create-admin', ['--dev' => true]))->toBe(1);
});

it('prints a signed but expired token that the API answers with TOKEN_EXPIRED', function (): void {
    User::factory()->create(['email' => 'lina@example.com']);

    Artisan::call('jwt:issue-expired', ['email' => 'lina@example.com']);
    $token = trim(Artisan::output());

    getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$token])
        ->assertUnauthorized()
        ->assertJsonPath('code', 'TOKEN_EXPIRED');
});

it('refuses to print expired tokens in production', function (): void {
    User::factory()->create(['email' => 'lina@example.com']);
    app()->detectEnvironment(fn (): string => 'production');

    expect(Artisan::call('jwt:issue-expired', ['email' => 'lina@example.com']))->toBe(1);
});

it('seeds the test customers outside production (D-04)', function (): void {
    config(['users.seed_customers.count' => 3, 'users.seed_customers.password' => 'seed-password-123']);

    seed(CustomerSeeder::class);
    seed(CustomerSeeder::class);

    expect(User::query()->where('role', 'customer')->pluck('email')->sort()->values()->all())
        ->toBe(['customer1@example.test', 'customer2@example.test', 'customer3@example.test']);
});

it('does not seed customers in production', function (): void {
    config(['users.seed_customers.count' => 3, 'users.seed_customers.password' => 'seed-password-123']);
    app()->detectEnvironment(fn (): string => 'production');

    seed(CustomerSeeder::class);

    expect(User::query()->count())->toBe(0);
});

it('generates Ed25519 keys readable only by the owner', function (): void {
    $directory = sys_get_temp_dir().'/jwt-keys-'.bin2hex(random_bytes(4));
    config(['jwt.keys_path' => $directory]);
    app()->forgetInstance(App\Shared\Auth\JwtConfig::class);
    app()->forgetInstance(JwtKeyStore::class);

    expect(Artisan::call('jwt:keys'))->toBe(0);
    $kid = app(JwtKeyStore::class)->activeKid();

    expect(fileperms($directory.'/'.$kid.'.secret') & 0777)->toBe(0600);

    expect(Artisan::call('jwt:keys'))->toBe(0);
    expect(app(JwtKeyStore::class)->activeKid())->toBe($kid);
});
