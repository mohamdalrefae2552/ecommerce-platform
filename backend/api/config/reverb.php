<?php

declare(strict_types=1);

// The WebSocket server itself (`php artisan reverb:start`, service `reverb`).
return [
    'default' => 'reverb',

    'servers' => [
        'reverb' => [
            'host' => env('REVERB_SERVER_HOST', '0.0.0.0'),
            'port' => env('REVERB_SERVER_PORT', 8080),
            'path' => '',
            'hostname' => env('REVERB_HOST'),
            'options' => ['tls' => []],
            'max_request_size' => 10_000,
            // One Reverb instance; horizontal scaling (Redis pub/sub between
            // instances) belongs to requirement 5.
            'scaling' => ['enabled' => false],
            'pulse_ingest_interval' => 15,
            'telescope_ingest_interval' => 15,
        ],
    ],

    'apps' => [
        'provider' => 'config',
        'apps' => [
            [
                'key' => env('REVERB_APP_KEY'),
                'secret' => env('REVERB_APP_SECRET'),
                'app_id' => env('REVERB_APP_ID'),
                'options' => [
                    'host' => env('REVERB_HOST'),
                    'port' => env('REVERB_PORT', 8080),
                    'scheme' => env('REVERB_SCHEME', 'http'),
                    'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
                ],
                // Only pages served from these hosts may open a socket
                // (docs/04: no cross-site WebSocket hijacking).
                'allowed_origins' => array_values(array_filter(array_map(trim(...), explode(',', (string) env('REVERB_ALLOWED_ORIGINS', 'localhost'))))),
                'ping_interval' => 60,
                'activity_timeout' => 30,
                'max_connections' => env('REVERB_APP_MAX_CONNECTIONS'),
                'max_message_size' => 10_000,
                // Browsers only listen: nobody may publish from a socket.
                'accept_client_events_from' => 'none',
                'rate_limiting' => [
                    'enabled' => true,
                    'max_attempts' => 60,
                    'decay_seconds' => 60,
                    'terminate_on_limit' => false,
                ],
            ],
        ],
    ],
];
