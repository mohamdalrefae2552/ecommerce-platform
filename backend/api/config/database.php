<?php

declare(strict_types=1);

/*
| Only what differs from the framework defaults (merged by Laravel).
*/

return [

    /*
    | Requirement 3 (ADR-0014): reads go to the streaming replica
    | (DB_READ_HOST), writes to the primary (DB_HOST). `sticky`: once a
    | request has written, its later reads use the primary too. Without
    | DB_READ_HOST both are the primary (tests, scheduler).
    */
    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            'read' => [
                'host' => [env('DB_READ_HOST', env('DB_HOST', '127.0.0.1'))],
            ],
            'write' => [
                'host' => [env('DB_HOST', '127.0.0.1')],
            ],
            'sticky' => true,
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ],
    ],

    /*
    | Across requests (App\Shared\Http\Middleware\ReadYourWrites): a caller
    | that wrote reads from the primary for this many seconds, far longer
    | than the replication lag (milliseconds).
    */
    'read_your_writes_seconds' => (int) env('DB_READ_YOUR_WRITES_SECONDS', 5),

    /*
    | Redis (requirement 3): the cache store (catalogue cache, rate limiters).
    | Every call has a deadline: connect 1 s, read 1 s.
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
