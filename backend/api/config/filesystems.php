<?php

declare(strict_types=1);

// Product images are written to the "public" disk (storage/app/public, the
// product-images volume) and served by edge from /storage/products, never by
// Laravel: no disk registers a /storage route ("serve" => false), so the
// application exposes only the endpoints of the OpenAPI contract.
return [

    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

    ],

    'links' => [],

];
