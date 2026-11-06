<?php

declare(strict_types=1);

/*
| Only what differs from the framework defaults (merged by Laravel).
*/

return [

    /*
    | Redis (requirement 3): the cache store (catalogue cache, rate limiters).
    | Every call has a deadline (CLAUDE.md rule 6): connect 1 s, read 1 s.
    */
    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => 'redis',
            'prefix' => env('REDIS_PREFIX', 'ecommerce:'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', '6379'),
            'password' => env('REDIS_PASSWORD'),
            'database' => env('REDIS_DB', '0'),
            'timeout' => (float) env('REDIS_TIMEOUT', 1.0),
            'read_timeout' => (float) env('REDIS_READ_TIMEOUT', 1.0),
            'max_retries' => 1,
        ],

        'cache' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', '6379'),
            'password' => env('REDIS_PASSWORD'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'timeout' => (float) env('REDIS_TIMEOUT', 1.0),
            'read_timeout' => (float) env('REDIS_READ_TIMEOUT', 1.0),
            'max_retries' => 1,
        ],
    ],

];
