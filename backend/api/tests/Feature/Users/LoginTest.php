<?php

declare(strict_types=1);

use App\Modules\Users\Database\Factories\UserFactory;
use App\Modules\Users\Models\User;
use Symfony\Component\HttpFoundation\Cookie;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

it('logs in with an access token and a strict refresh cookie', function (): void {
    $user = User::factory()->create(['email' => 'lina@example.com']);

    $response = postJson('/api/v1/auth/login', ['email' => 'LINA@example.com', 'password' => UserFactory::PASSWORD])
        ->assertOk()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.expires_in', 900)
        ->assertJsonPath('data.user.id', $user->id)
        ->assertMatchesContract();

    $cookie = $response->getCookie('refresh_token', false);
    expect($cookie)->toBeInstanceOf(Cookie::class);
    assert($cookie instanceof Cookie);
    expect($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->getSameSite())->toBe(Cookie::SAMESITE_STRICT)
        ->and($cookie->getPath())->toBe('/api/v1/auth')
        ->and($cookie->getMaxAge())->toBe(14 * 86400)
        ->and(strlen((string) $cookie->getValue()))->toBeGreaterThanOrEqual(43);

    $token = $response->json('data.access_token');
    assert(is_string($token));

    getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.email', 'lina@example.com')
        ->assertMatchesContract();
});

it('gives the same answer for an unknown e-mail, a wrong password and a disabled account', function (string $email, string $password): void {
    User::factory()->create(['email' => 'lina@example.com']);
    User::factory()->disabled()->create(['email' => 'off@example.com']);

    postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])
        ->assertUnauthorized()
        ->assertJsonPath('code', 'INVALID_CREDENTIALS')
        ->assertJsonPath('detail', 'البريد الإلكتروني أو كلمة المرور غير صحيحة.')
        ->assertHeader('WWW-Authenticate', 'Bearer')
        ->assertMatchesContract();
})->with([
    'unknown e-mail' => ['nobody@example.com', UserFactory::PASSWORD],
    'wrong password' => ['lina@example.com', 'wrong-password-123'],
    'disabled account' => ['off@example.com', UserFactory::PASSWORD],
]);

it('requires a token for /auth/me', function (): void {
    getJson('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'UNAUTHENTICATED')
        ->assertHeader('WWW-Authenticate', 'Bearer')
        ->assertMatchesContract();
});
