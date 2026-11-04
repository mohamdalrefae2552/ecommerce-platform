<?php

declare(strict_types=1);

// Passwords: argon2id (docs/04 section 2).
return [
    'driver' => 'argon2id',

    'argon' => [
        'memory' => (int) env('ARGON_MEMORY', 65536),
        'threads' => (int) env('ARGON_THREADS', 1),
        'time' => (int) env('ARGON_TIME', 4),
        'verify' => true,
    ],

    'rehash_on_login' => true,
];
