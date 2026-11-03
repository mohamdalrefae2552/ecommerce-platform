<?php

declare(strict_types=1);

// Access tokens (docs/04 section 2, ADR-0003). Values come from compose (x-api-env).
return [
    'issuer' => env('JWT_ISSUER', 'http://localhost:8080'),
    'audience' => env('JWT_AUDIENCE', 'ecommerce-api'),
    'access_ttl' => (int) env('JWT_ACCESS_TTL', 900),
    'leeway' => (int) env('JWT_LEEWAY', 30),
    'refresh_ttl_days' => (int) env('JWT_REFRESH_TTL_DAYS', 14),
    // A volume (jwt-keys), never committed; created by `php artisan jwt:keys`.
    'keys_path' => env('JWT_KEYS_PATH', storage_path('keys')),
];
