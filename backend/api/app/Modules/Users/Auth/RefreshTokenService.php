<?php

declare(strict_types=1);

namespace App\Modules\Users\Auth;

use App\Modules\Users\Models\RefreshToken;
use App\Modules\Users\Models\User;
use App\Shared\Auth\JwtConfig;
use App\Shared\Http\Problems\ProblemCode;
use App\Shared\Http\Problems\ProblemException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Opaque rotating refresh tokens (docs/04 section 2). 256 random bits, only
 * the SHA-256 is stored. Every rotation revokes the presented token and
 * issues a new one in the same family; presenting a revoked token again is
 * treated as theft and revokes the whole family.
 */
final readonly class RefreshTokenService
{
    public function __construct(private JwtConfig $config) {}

    /**
     * Starts a new family (login). Returns the plain token for the cookie.
     */
    public function issue(User $user): string
    {
        return $this->create($user->id, (string) Str::ulid())[0];
    }

    /**
     * @return array{0: User, 1: string} the owner and the new plain token
     */
    public function rotate(string $plain): array
    {
        $outcome = DB::transaction(function () use ($plain): array|string {
            $token = RefreshToken::query()->where('token_hash', self::hash($plain))->lockForUpdate()->first();

            if ($token === null) {
                return 'invalid';
            }

            if ($token->revoked_at !== null) {
                $this->revokeFamily($token->family_id);

                return 'reused';
            }

            $user = User::query()->find($token->user_id);

            if ($token->expires_at->isPast() || $user === null || $user->isDisabled()) {
                return 'invalid';
            }

            [$next, $nextId] = $this->create($user->id, $token->family_id);
            $token->forceFill(['revoked_at' => Carbon::now(), 'replaced_by' => $nextId])->save();

            return [$user, $next];
        });

        if (! is_array($outcome)) {
            throw new ProblemException(ProblemCode::RefreshTokenInvalid);
        }

        return $outcome;
    }

    /**
     * Revokes the family of the presented token (logout), if it belongs to the user.
     */
    public function revokeFamilyOf(string $plain, User $user): void
    {
        $token = RefreshToken::query()
            ->where('token_hash', self::hash($plain))
            ->where('user_id', $user->id)
            ->first();

        if ($token !== null) {
            $this->revokeFamily($token->family_id);
        }
    }

    public function ttlSeconds(): int
    {
        return $this->config->refreshTtlDays * 86400;
    }

    private function revokeFamily(string $familyId): void
    {
        RefreshToken::query()
            ->where('family_id', $familyId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => Carbon::now()]);
    }

    /**
     * @return array{0: string, 1: string} plain token and row id
     */
    private function create(string $userId, string $familyId): array
    {
        $plain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $token = RefreshToken::query()->create([
            'user_id' => $userId,
            'family_id' => $familyId,
            'token_hash' => self::hash($plain),
            'expires_at' => Carbon::now()->addSeconds($this->ttlSeconds()),
        ]);

        return [$plain, $token->id];
    }

    private static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
