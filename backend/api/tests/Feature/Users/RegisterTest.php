<?php

declare(strict_types=1);

use App\Modules\Users\Models\User;
use App\Shared\Auth\Role;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\postJson;

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function registration(array $overrides = []): array
{
    return [
        'name' => 'Lina Haddad',
        'email' => 'Lina@Example.com',
        'password' => 'correct-horse-battery',
        'password_confirmation' => 'correct-horse-battery',
        ...$overrides,
    ];
}

it('registers a customer with 201, Location and no password in the answer', function (): void {
    $response = postJson('/api/v1/auth/register', registration())
        ->assertCreated()
        ->assertHeader('Location', url('/api/v1/auth/me'))
        ->assertJsonPath('data.email', 'lina@example.com')
        ->assertJsonPath('data.role', 'customer')
        ->assertMatchesContract();

    expect((string) $response->getContent())->not->toContain('password');

    $user = User::query()->sole();
    expect($user->role)->toBe(Role::Customer)
        ->and($user->password)->toStartWith('$argon2id$');
});

it('rejects a duplicate e-mail with 422', function (): void {
    User::factory()->create(['email' => 'lina@example.com']);

    postJson('/api/v1/auth/register', registration())
        ->assertUnprocessable()
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['errors' => ['email']])
        ->assertMatchesContract();
});

it('never lets the client choose a role (mass assignment)', function (): void {
    postJson('/api/v1/auth/register', registration(['role' => 'admin']))
        ->assertUnprocessable()
        ->assertJsonStructure(['errors' => ['role']])
        ->assertMatchesContract();

    assertDatabaseCount('users', 0);
});

it('rejects invalid input', function (array $input, string $field): void {
    /** @var array<string, string> $input */
    postJson('/api/v1/auth/register', registration($input))
        ->assertUnprocessable()
        ->assertJsonStructure(['errors' => [$field]])
        ->assertMatchesContract();
})->with([
    'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'password too long' => [['password' => str_repeat('a', 129), 'password_confirmation' => str_repeat('a', 129)], 'password'],
    'confirmation mismatch' => [['password_confirmation' => 'something-else-entirely'], 'password'],
    'bad e-mail' => [['email' => 'not-an-email'], 'email'],
    'short name' => [['name' => 'L'], 'name'],
]);
