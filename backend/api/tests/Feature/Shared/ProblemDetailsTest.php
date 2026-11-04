<?php

declare(strict_types=1);

use App\Shared\Http\Middleware\RequestId;
use App\Shared\Http\Problems\ProblemCode;
use App\Shared\Http\Problems\ProblemException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response as AccessResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\call;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
| Every error is RFC 9457 problem+json with a catalog code (contracts/errors.md).
| The routes under /api/v1/__test are registered by this file only.
*/

beforeEach(function (): void {
    Route::middleware('api')->prefix('api/v1/__test')->group(function (): void {
        Route::post('/validation', fn (Request $request): mixed => $request->validate(['email' => ['required', 'email']]));
        Route::get('/unauthenticated', fn () => throw new AuthenticationException);
        Route::get('/forbidden', fn () => throw new AuthorizationException);
        Route::get('/hidden', fn () => AccessResponse::denyAsNotFound()->authorize());
        Route::get('/missing-model', fn () => throw new ModelNotFoundException);
        Route::get('/domain', fn () => throw new ProblemException(ProblemCode::OrderInvalidTransition, 'لا يمكن نقل الطلب من الحالة delivered إلى pending.'));
        Route::get('/boom', fn () => throw new RuntimeException('SQLSTATE secret /var/www/path'));
        Route::post('/echo', fn (Request $request): array => $request->all());
        Route::get('/throttled', fn (): array => [])->middleware(ThrottleRequests::using('test-limit'));
    });
    RateLimiter::for('test-limit', fn () => Illuminate\Cache\RateLimiting\Limit::perMinute(1));
});

/**
 * @param  TestResponse<Response>  $response
 * @return TestResponse<Response>
 */
function assertProblem(TestResponse $response, ProblemCode $code): TestResponse
{
    return $response
        ->assertStatus($code->status())
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', $code->value)
        ->assertJsonPath('status', $code->status())
        ->assertJsonPath('type', $code->type())
        ->assertJsonPath('trace_id', $response->headers->get(RequestId::HEADER))
        ->assertMatchesContractSchema('Problem');
}

it('answers an unknown route with 404 NOT_FOUND', function (): void {
    assertProblem(getJson('/api/v1/does-not-exist'), ProblemCode::NotFound)
        ->assertJsonPath('instance', '/api/v1/does-not-exist');
});

it('answers an unsupported method with 405 and Allow', function (): void {
    assertProblem(postJson('/api/v1/health'), ProblemCode::MethodNotAllowed)
        ->assertHeader('Allow');
});

it('answers invalid input with 422 VALIDATION_FAILED and the field errors', function (): void {
    assertProblem(postJson('/api/v1/__test/validation', ['email' => 'nope']), ProblemCode::ValidationFailed)
        ->assertJsonStructure(['errors' => ['email']])
        ->assertMatchesContractSchema('ValidationProblem');
});

it('answers a missing authentication with 401 and WWW-Authenticate', function (): void {
    assertProblem(getJson('/api/v1/__test/unauthenticated'), ProblemCode::Unauthenticated)
        ->assertHeader('WWW-Authenticate', 'Bearer');
});

it('answers a denied authorization with 403 FORBIDDEN', function (): void {
    assertProblem(getJson('/api/v1/__test/forbidden'), ProblemCode::Forbidden);
});

it('answers a denial as not found with 404 (D-02)', function (): void {
    assertProblem(getJson('/api/v1/__test/hidden'), ProblemCode::NotFound);
});

it('answers a missing model with 404 NOT_FOUND', function (): void {
    assertProblem(getJson('/api/v1/__test/missing-model'), ProblemCode::NotFound);
});

it('renders domain problems with their own code and detail', function (): void {
    assertProblem(getJson('/api/v1/__test/domain'), ProblemCode::OrderInvalidTransition)
        ->assertJsonPath('detail', 'لا يمكن نقل الطلب من الحالة delivered إلى pending.');
});

it('answers a tripped rate limiter with 429, Retry-After and retry_after', function (): void {
    getJson('/api/v1/__test/throttled')->assertOk();

    $response = assertProblem(getJson('/api/v1/__test/throttled'), ProblemCode::RateLimited)
        ->assertHeader('Retry-After')
        ->assertHeader('X-RateLimit-Limit', '1')
        ->assertHeader('X-RateLimit-Remaining', '0')
        ->assertMatchesContractSchema('RateLimitedProblem');

    expect($response->json('retry_after'))->toBe((int) $response->headers->get('Retry-After'));
});

it('answers a malformed JSON body with 400 MALFORMED_REQUEST', function (): void {
    assertProblem(
        call('POST', '/api/v1/__test/echo', server: ['CONTENT_TYPE' => 'application/json'], content: '{"broken":'),
        ProblemCode::MalformedRequest,
    );
});

it('answers a non-JSON body with 415 UNSUPPORTED_MEDIA_TYPE', function (): void {
    assertProblem(
        call('POST', '/api/v1/__test/echo', server: ['CONTENT_TYPE' => 'text/plain'], content: 'hello'),
        ProblemCode::UnsupportedMediaType,
    );
});

it('hides every detail of an unexpected failure, even in debug mode', function (bool $debug): void {
    config(['app.debug' => $debug]);

    $response = assertProblem(getJson('/api/v1/__test/boom'), ProblemCode::InternalError);

    $content = (string) $response->getContent();

    expect(str_contains($content, 'SQLSTATE'))->toBeFalse()
        ->and(str_contains($content, '/var/www'))->toBeFalse()
        ->and(array_keys((array) $response->json()))->toBe(['type', 'title', 'status', 'detail', 'instance', 'code', 'trace_id']);
})->with(['debug off' => false, 'debug on' => true]);
