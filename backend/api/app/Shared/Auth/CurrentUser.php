<?php

declare(strict_types=1);

namespace App\Shared\Auth;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

/**
 * The authenticated user of a request behind the `auth` middleware.
 */
final class CurrentUser
{
    public static function of(Request $request): AuthUser
    {
        $user = $request->user();

        if (! $user instanceof AuthUser) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
