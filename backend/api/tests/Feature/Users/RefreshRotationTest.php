<?php

declare(strict_types=1);

use App\Modules\Users\Database\Factories\UserFactory;
use App\Modules\Users\Models\RefreshToken;
use App\Modules\Users\Models\User;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\call;
use function Pest\Laravel\postJson;

/*
| Rotating refresh tokens with theft detection (docs/04 section 2).
*/

const CSRF_HEADERS = ['HTTP_X_REQUESTED_WITH' => 'fetch', 'HTTP_ORIGIN' => 'http://localhost:8080'];

function loginCookie(User $user): string
{
    $cookie = postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD])
        ->assertOk()
        ->getCookie('refresh_token', false);

    return (string) $cookie?->getValue();
}

/**
 * @param  array<string, string>  $server
 * @return TestResponse<Response>
 */
function refreshWith(?string $cookie, array $server = CSRF_HEADERS): TestResponse
{
    return call('POST', '/api/v1/auth/refresh', cookies: $cookie === null ? [] : ['refresh_token' => $cookie], server: $server + ['HTTP_ACCEPT' => 'application/json']);
}

beforeEach(function (): void {
    config(['app.url' => 'http://localhost:8080']);
});

it('rotates: the new token works and the old one is revoked', function (): void {
    $old = loginCookie(User::factory()->create());

    $response = refreshWith($old)->assertOk()->assertMatchesContract();
    $new = (string) $response->getCookie('refresh_token', false)?->getValue();

    expect($new === $old)->toBeFalse()->and($new === '')->toBeFalse();
    refreshWith($new)->assertOk();
});

it('revokes the whole family when a revoked token is re-used', function (): void {
    $old = loginCookie(User::factory()->create());
    $new = (string) refreshWith($old)->getCookie('refresh_token', false)?->getValue();

    refreshWith($old)
        ->assertUnauthorized()
        ->assertJsonPath('code', 'REFRESH_TOKEN_INVALID')
        ->assertMatchesContract();

    refreshWith($new)->assertUnauthorized()->assertJsonPath('code', 'REFRESH_TOKEN_INVALID');
    expect(RefreshToken::query()->whereNull('revoked_at')->count())->toBe(0);
});

it('refuses with 403 and rotates nothing when the CSRF guard fails', function (array $server): void {
    /** @var array<string, string> $server */
    $token = loginCookie(User::factory()->create());

    refreshWith($token, $server)
        ->assertForbidden()
        ->assertJsonPath('code', 'FORBIDDEN')
        ->assertMatchesContract();

    refreshWith($token)->assertOk();
})->with([
    'no X-Requested-With' => [['HTTP_ORIGIN' => 'http://localhost:8080']],
    'foreign Origin' => [['HTTP_X_REQUESTED_WITH' => 'fetch', 'HTTP_ORIGIN' => 'https://evil.example']],
    'no Origin' => [['HTTP_X_REQUESTED_WITH' => 'fetch']],
]);

it('answers a missing or unknown cookie with 401 REFRESH_TOKEN_INVALID', function (?string $cookie): void {
    refreshWith($cookie)
        ->assertUnauthorized()
        ->assertJsonPath('code', 'REFRESH_TOKEN_INVALID')
        ->assertMatchesContract();
})->with(['missing' => [null], 'unknown' => ['not-a-real-token']]);

it('rejects an expired refresh token', function (): void {
    $token = loginCookie(User::factory()->create());

    Pest\Laravel\travel(15)->days();

    refreshWith($token)->assertUnauthorized()->assertJsonPath('code', 'REFRESH_TOKEN_INVALID');
});

it('logs out: 204, cookie deleted, family revoked', function (): void {
    $user = User::factory()->create();
    $token = loginCookie($user);

    $response = call('POST', '/api/v1/auth/logout', cookies: ['refresh_token' => $token], server: [
        'HTTP_AUTHORIZATION' => 'Bearer '.jwtFor($user),
        'HTTP_ACCEPT' => 'application/json',
    ])->assertNoContent()->assertMatchesContract();

    expect($response->getCookie('refresh_token', false)?->getExpiresTime())->toBeLessThan(time());
    refreshWith($token)->assertUnauthorized();
});

it('requires authentication to log out', function (): void {
    postJson('/api/v1/auth/logout')->assertUnauthorized()->assertMatchesContract();
});
