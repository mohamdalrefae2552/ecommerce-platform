<?php

declare(strict_types=1);

namespace App\Modules\Products\Policies;

use App\Shared\Auth\AuthUser;

/**
 * Catalogue writes are admin-only (docs/02 section 3, API5). Reads are public
 * and need no policy.
 */
final class ProductPolicy
{
    public function create(AuthUser $user): bool
    {
        return $user->isAdmin();
    }

    public function adjustStock(AuthUser $user): bool
    {
        return $user->isAdmin();
    }
}
