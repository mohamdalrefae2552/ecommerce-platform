<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Controllers;

use App\Modules\Users\Auth\RefreshCookie;
use App\Modules\Users\Auth\RefreshTokenService;
use App\Modules\Users\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /api/v1/auth/logout (logoutUser): revokes the refresh token family
 * and deletes the cookie. The access token simply expires (15 minutes).
 */
final readonly class LogoutController
{
    public function __construct(private RefreshTokenService $refreshTokens) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $plain = $request->cookies->get(RefreshCookie::NAME);

        if ($user instanceof User && is_string($plain) && $plain !== '') {
            $this->refreshTokens->revokeFamilyOf($plain, $user);
        }

        return (new Response(status: 204))->withCookie(RefreshCookie::forget());
    }
}
