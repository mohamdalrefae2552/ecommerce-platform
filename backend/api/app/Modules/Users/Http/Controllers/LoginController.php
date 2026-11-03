<?php

declare(strict_types=1);

namespace App\Modules\Users\Http\Controllers;

use App\Modules\Users\Actions\AuthenticateUser;
use App\Modules\Users\Auth\RefreshTokenService;
use App\Modules\Users\Auth\TokenResponder;
use App\Modules\Users\Http\Requests\LoginRequest;
use App\Modules\Users\Providers\UsersServiceProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;

/**
 * POST /api/v1/auth/login (loginUser). A successful login clears the
 * (email, IP) attempt counter.
 */
final readonly class LoginController
{
    public function __construct(
        private AuthenticateUser $authenticate,
        private RefreshTokenService $refreshTokens,
        private TokenResponder $responder,
    ) {}

    public function __invoke(LoginRequest $request): JsonResponse
    {
        $user = $this->authenticate->handle($request->email(), $request->password());

        RateLimiter::clear(UsersServiceProvider::loginAttemptKey($request->email(), (string) $request->ip()));

        return $this->responder->respond($request, $user, $this->refreshTokens->issue($user));
    }
}
