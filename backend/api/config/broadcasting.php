<?php

declare(strict_types=1);

// Requirement 4 (ADR-0016): live updates go through Reverb, the WebSocket
// server of the `reverb` service. The `worker-realtime` service publishes to
// it over HTTP; browsers connect through edge (/app/...).
return [
    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [
        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 8080),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            ],
            // Explicit deadlines (golden rule 6): a Reverb that is down fails
            // the broadcast quickly; the worker then retries the message.
            'client_options' => [
                'connect_timeout' => 2,
                'timeout' => 5,
                // Requirement 5: the worker is a long-lived process and cURL
                // keeps resolved addresses for 60 s. When Reverb instances are
                // replaced (new IPs) it kept calling the old ones and every
                // live update failed for a minute; 5 s follows Docker's DNS.
                'curl' => [CURLOPT_DNS_CACHE_TIMEOUT => 5],
            ],
        ],
    ],
];
