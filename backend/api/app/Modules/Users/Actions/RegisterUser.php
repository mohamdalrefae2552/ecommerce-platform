<?php

declare(strict_types=1);

namespace App\Modules\Users\Actions;

use App\Modules\Users\Models\User;
use App\Shared\Auth\Role;

/**
 * Registration: always a customer (the role is never taken from input).
 */
final readonly class RegisterUser
{
    public function handle(string $name, string $email, string $password): User
    {
        $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->role = Role::Customer;
        $user->save();

        return $user;
    }
}
