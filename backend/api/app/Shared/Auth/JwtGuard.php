<?php

declare(strict_types=1);

namespace App\Shared\Auth;

use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;

/**
 * `jwt` guard (config/auth.php): Bearer access token ⇒ user loaded from the
 * database on every request. The role comes from the database, never from
 * the token claim, so a revoked role or a disabled account applies at once.
 */
final class JwtGuard implements Guard
{
    use GuardHelpers;

    private bool $resolved = false;

    public function __construct(
        UserProvider $provider,
        private readonly TokenVerifier $verifier,
        private Request $request,
    ) {
        $this->provider = $provider;
    }

    public function user(): ?Authenticatable
    {
        if ($this->resolved) {
            return $this->user;
        }

        $this->resolved = true;
        $token = $this->request->bearerToken();

        if ($token === null || $token === '') {
            return null;
        }

        $subject = $this->verifier->subject($token);
        $user = $subject === null ? null : $this->provider->retrieveById($subject);

        if (! $user instanceof AuthUser || $user->isDisabled()) {
            return null;
        }

        return $this->user = $user;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function validate(array $credentials = []): bool
    {
        return false;
    }

    public function setRequest(Request $request): self
    {
        $this->request = $request;
        $this->user = null;
        $this->resolved = false;

        return $this;
    }
}
