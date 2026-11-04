<?php

declare(strict_types=1);

// Non-production helpers of the Users module (decisions D-04 and D-05).
return [
    // `db:seed` creates customer{N}@example.test with this password.
    'seed_customers' => [
        'count' => (int) env('SEED_CUSTOMER_COUNT', 0),
        'password' => (string) env('SEED_CUSTOMER_PASSWORD', ''),
    ],

    // `php artisan app:create-admin --dev` creates or updates this admin.
    'dev_admin' => [
        'email' => (string) env('DEV_ADMIN_EMAIL', ''),
        'password' => (string) env('DEV_ADMIN_PASSWORD', ''),
    ],
];
