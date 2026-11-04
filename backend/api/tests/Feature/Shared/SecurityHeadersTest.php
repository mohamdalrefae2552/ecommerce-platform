<?php

declare(strict_types=1);

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

it('sends every security header on API responses', function (string $header, string $value): void {
    getJson('/api/v1/health')->assertHeader($header, $value);
})->with([
    ['X-Content-Type-Options', 'nosniff'],
    ['X-Frame-Options', 'DENY'],
    ['Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'"],
    ['Referrer-Policy', 'no-referrer'],
    ['Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()'],
    ['Cross-Origin-Opener-Policy', 'same-origin'],
    ['Cross-Origin-Resource-Policy', 'same-origin'],
]);

it('sends the security headers on errors too', function (): void {
    getJson('/api/v1/does-not-exist')
        ->assertNotFound()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY');
});

it('sends HSTS only over HTTPS', function (): void {
    getJson('/api/v1/health')->assertHeaderMissing('Strict-Transport-Security');
    get('https://localhost/api/v1/health')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('forbids caching of authenticated responses', function (): void {
    expect((string) getJson('/api/v1/health', ['Authorization' => 'Bearer x'])->headers->get('Cache-Control'))
        ->toContain('no-store');
});

it('answers JSON whatever the Accept header asks for', function (): void {
    get('/api/v1/does-not-exist', ['Accept' => 'text/html'])
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json');
});
