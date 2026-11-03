<?php

declare(strict_types=1);

namespace App\Shared\Auth;

/**
 * Roles (docs/02 section 3). `customer` is the default on registration;
 * `admin` is only created by `php artisan app:create-admin`.
 */
enum Role: string
{
    case Customer = 'customer';
    case Admin = 'admin';
}
