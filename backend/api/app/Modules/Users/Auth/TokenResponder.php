<?php

declare(strict_types=1);

namespace App\Modules\Users\Auth;

use App\Modules\Users\Http\Resources\UserResource;
use App\Modules\Users\Models\User;
use App\Shared\Auth\TokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Login and refresh answer (contract response TokenIssued): a new access
 * token in the body and the new refresh token in the cookie.
 */
final readonly class TokenResponder
{
    public function __construct(
        private TokenIssuer $issuer,
        private RefreshTokenService $refreshTokens,
    ) {}

    public function respond(Request $request, User $user, string $refreshToken): JsonResponse
    {
        $access = $this->issuer->issue($user);

        return (new JsonResponse([
            'data' => [
                'access_token' => $access->value,
                'token_type' => 'Bearer',
                'expires_in' => $access->expiresIn,
                'user' => (new UserResource($user))->toArray($request),
            ],
        ]))->withCookie(RefreshCookie::make($refreshToken, $this->refreshTokens->ttlSeconds()));
    }
}
