<?php

declare(strict_types=1);

// Mail is sent only by the `worker-mail` service (requirement 4). Mailpit in
// development; a real provider (Gmail SMTP) by setting MAIL_* in .env.
return [
    'default' => env('MAIL_MAILER', 'smtp'),

    'mailers' => [
        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'host' => env('MAIL_HOST', 'mailpit'),
            'port' => env('MAIL_PORT', 1025),
            'username' => env('MAIL_USERNAME') ?: null,
            'password' => env('MAIL_PASSWORD') ?: null,
            // Deadline for the SMTP conversation (golden rule 6): a mail
            // server that hangs fails the attempt; the worker retries later.
            'timeout' => (int) env('MAIL_TIMEOUT', 10),
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],
    ],

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'no-reply@ecommerce.local'),
        'name' => env('MAIL_FROM_NAME', 'E-commerce Platform'),
    ],
];
