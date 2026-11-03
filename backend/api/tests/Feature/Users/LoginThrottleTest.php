<?php

declare(strict_types=1);

use App\Modules\Users\Database\Factories\UserFactory;
use App\Modules\Users\Models\User;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\call;
use function Pest\Laravel\postJson;
use function Pest\Laravel\travel;

/*
| Login and registration rate limits (docs/04 section 3, R1-SEC2).
*/

/**
 * @param  array<string, string>  $server
 * @return TestResponse<Response>
 */
function attempt(string $email, string $password, array $server = []): TestResponse
{
    return call('POST', '/api/v1/auth/login', server: $server + ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: (string) json_encode(['email' => $email, 'password' => $password]));
}

it('blocks the 6th attempt on one e-mail, even with the right password', function (): void {
    User::factory()->create(['email' => 'lina@example.com']);

    foreach (range(1, 5) as $ignored) {
        attempt('lina@example.com', 'wrong-password-123')->assertUnauthorized();
    }

    $response = attempt('lina@example.com', UserFactory::PASSWORD)
        ->assertTooManyRequests()
        ->assertJsonPath('code', 'RATE_LIMITED')
        ->assertHeader('Retry-After')
        ->assertMatchesContract();

    expect($response->json('retry_after'))->toBeGreaterThan(0);

    travel(61)->seconds();
    attempt('lina@example.com', UserFactory::PASSWORD)->assertOk();
});

it('blocks one IP spraying 20 different e-mails', function (): void {
    foreach (range(1, 20) as $n) {
        attempt("user{$n}@example.com", 'wrong-password-123')->assertUnauthorized();
    }

    attempt('user21@example.com', 'wrong-password-123')->assertTooManyRequests();
});

it('ignores a spoofed X-Forwarded-For', function (): void {
    foreach (range(1, 5) as $n) {
        attempt('lina@example.com', 'wrong-password-123', ['HTTP_X_FORWARDED_FOR' => "203.0.113.{$n}"])->assertUnauthorized();
    }

    attempt('lina@example.com', 'wrong-password-123', ['HTTP_X_FORWARDED_FOR' => '198.51.100.7'])->assertTooManyRequests();
});

it('clears the e-mail counter after a successful login', function (): void {
    User::factory()->create(['email' => 'lina@example.com']);

    foreach (range(1, 4) as $ignored) {
        attempt('lina@example.com', 'wrong-password-123')->assertUnauthorized();
    }
    attempt('lina@example.com', UserFactory::PASSWORD)->assertOk();

    foreach (range(1, 4) as $ignored) {
        attempt('lina@example.com', 'wrong-password-123')->assertUnauthorized();
    }
});

it('allows 5 registrations per hour per IP', function (): void {
    foreach (range(1, 5) as $n) {
        postJson('/api/v1/auth/register', ['name' => 'User '.$n, 'email' => "new{$n}@example.com", 'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery'])->assertCreated();
    }

    postJson('/api/v1/auth/register', ['name' => 'User 6', 'email' => 'new6@example.com', 'password' => 'correct-horse-battery', 'password_confirmation' => 'correct-horse-battery'])
        ->assertTooManyRequests()
        ->assertMatchesContract();
});
