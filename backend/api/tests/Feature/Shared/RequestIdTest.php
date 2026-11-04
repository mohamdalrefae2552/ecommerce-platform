<?php

declare(strict_types=1);

use App\Shared\Http\Middleware\RequestId;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

use function Pest\Laravel\getJson;

it('generates a ULID request id when the request has none', function (): void {
    $id = getJson('/api/v1/health')->assertOk()->headers->get(RequestId::HEADER);

    expect(is_string($id) && Str::isUlid($id))->toBeTrue();
});

it('echoes a well-formed request id and adds it to the log context', function (): void {
    getJson('/api/v1/health', [RequestId::HEADER => '01JC3Z9A1B2C3D4E5F6G7H8J9K'])
        ->assertOk()
        ->assertHeader(RequestId::HEADER, '01JC3Z9A1B2C3D4E5F6G7H8J9K');

    expect(Context::get(RequestId::CONTEXT_KEY))->toBe('01JC3Z9A1B2C3D4E5F6G7H8J9K');
});

it('replaces a malformed request id instead of reflecting it', function (string $malformed): void {
    $id = getJson('/api/v1/health', [RequestId::HEADER => $malformed])->assertOk()->headers->get(RequestId::HEADER);

    expect($id)->not->toBe($malformed)
        ->and(is_string($id) && Str::isUlid($id))->toBeTrue();
})->with([
    'log injection' => "abc\nINJECTED",
    'markup' => '<script>alert(1)</script>',
    'too long' => str_repeat('a', 129),
]);
