<?php

declare(strict_types=1);

use App\Modules\Users\Models\User;

// Stateless API: the only guard is the JWT guard (App\Shared\Auth\JwtGuard).
return [
    'defaults' => [
        'guard' => 'api',
        'passwords' => 'users',
    ],

    'guards' => [
        'api' => [
            'driver' => 'jwt',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],
    ],

    'passwords' => [],

    'password_timeout' => 10800,
];
