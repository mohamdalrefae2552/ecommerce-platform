<?php

declare(strict_types=1);

namespace App\Shared\Auth;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * What the shared auth layer needs from the user model (owned by the Users
 * module), so Shared never depends on a module.
 */
interface AuthUser extends Authenticatable
{
    public function userId(): string;

    public function userRole(): Role;

    public function isAdmin(): bool;

    public function isDisabled(): bool;
}
