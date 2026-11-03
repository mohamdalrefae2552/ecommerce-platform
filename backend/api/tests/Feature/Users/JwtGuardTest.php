<?php

declare(strict_types=1);

use App\Modules\Users\Models\User;
use App\Shared\Auth\JwtConfig;
use App\Shared\Auth\JwtKeyStore;
use App\Shared\Auth\Role;
use Illuminate\Support\Carbon;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer;
use Lcobucci\JWT\Signer\Eddsa;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Builder;

use function Pest\Laravel\getJson;
use function Pest\Laravel\travel;

/*
| JWT guard (docs/04 section 2, API2): only EdDSA tokens signed with a known
| kid, for the right issuer and audience, of an existing enabled user.
*/

/**
 * @param  array<string, non-empty-string>  $overrides  iss, aud, kid, sub
 * @param  non-empty-string|null  $key  plain-text key for $signer
 */
function forgedToken(User $user, array $overrides = [], ?Signer $signer = null, ?string $key = null): string
{
    $keys = app(JwtKeyStore::class);
    $config = app(JwtConfig::class);
    $now = Carbon::now()->toDateTimeImmutable();
    $subject = $user->id;
    assert($subject !== '');

    return (new Builder(new JoseEncoder, ChainedFormatter::default()))
        ->withHeader('kid', $overrides['kid'] ?? $keys->activeKid())
        ->issuedBy($overrides['iss'] ?? $config->issuer)
        ->permittedFor($overrides['aud'] ?? $config->audience)
        ->relatedTo($overrides['sub'] ?? $subject)
        ->identifiedBy('test')
        ->issuedAt($now)
        ->canOnlyBeUsedAfter($now)
        ->expiresAt($now->modify('+900 seconds'))
        ->withClaim('role', 'admin')
        ->getToken($signer ?? new Eddsa, $key === null ? InMemory::base64Encoded($keys->activeSecretKey()) : InMemory::plainText($key))
        ->toString();
}

function base64url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

it('accepts a token it issued', function (): void {
    $user = User::factory()->create();

    getJson('/api/v1/auth/me', bearer($user))->assertOk()->assertJsonPath('data.id', $user->id);
});

it('rejects forged or foreign tokens with 401 UNAUTHENTICATED', function (Closure $token): void {
    $user = User::factory()->create();

    $value = $token($user);
    assert(is_string($value));

    getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$value])
        ->assertUnauthorized()
        ->assertJsonPath('code', 'UNAUTHENTICATED')
        ->assertHeader('WWW-Authenticate', 'Bearer')
        ->assertMatchesContract();
})->with([
    'tampered payload' => [function (User $user): string {
        [$header, , $signature] = explode('.', jwtFor($user));

        return $header.'.'.base64url((string) json_encode(['sub' => $user->id, 'role' => 'admin'])).'.'.$signature;
    }],
    'alg none' => [fn (User $user): string => base64url((string) json_encode(['alg' => 'none', 'typ' => 'JWT', 'kid' => app(JwtKeyStore::class)->activeKid()]))
        .'.'.base64url((string) json_encode(['sub' => $user->id, 'iss' => app(JwtConfig::class)->issuer, 'aud' => app(JwtConfig::class)->audience, 'exp' => Carbon::now()->getTimestamp() + 900, 'nbf' => Carbon::now()->getTimestamp(), 'iat' => Carbon::now()->getTimestamp()])).'.'],
    'HS256 signed with the public key' => [fn (User $user): string => forgedToken($user, signer: new Sha256, key: app(JwtKeyStore::class)->publicKey(app(JwtKeyStore::class)->activeKid()))],
    'wrong audience' => [fn (User $user): string => forgedToken($user, ['aud' => 'another-api'])],
    'wrong issuer' => [fn (User $user): string => forgedToken($user, ['iss' => 'https://evil.example'])],
    'unknown kid' => [fn (User $user): string => forgedToken($user, ['kid' => 'unknown-kid'])],
    'unknown user' => [fn (User $user): string => forgedToken($user, ['sub' => '01JC3Z8X7Q4M2N5P6R7S8T9V0W'])],
    'not a JWT' => [fn (User $user): string => 'not-a-jwt'],
]);

it('rejects a disabled user even with a valid token', function (): void {
    $user = User::factory()->create();
    $token = jwtFor($user);
    $user->forceFill(['disabled_at' => Carbon::now()])->save();

    getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$token])
        ->assertUnauthorized()
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('answers an expired token with 401 TOKEN_EXPIRED, after the leeway', function (): void {
    $user = User::factory()->create();
    $token = jwtFor($user);

    travel(900 + 20)->seconds();
    getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$token])->assertOk();

    travel(20)->seconds();
    getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$token])
        ->assertUnauthorized()
        ->assertJsonPath('code', 'TOKEN_EXPIRED')
        ->assertHeader('WWW-Authenticate', 'Bearer')
        ->assertMatchesContract();
});

it('reads the role from the database, not from the token', function (): void {
    $user = User::factory()->create();
    $token = jwtFor($user);

    $user->role = Role::Admin;
    $user->save();

    getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$token])->assertOk()->assertJsonPath('data.role', 'admin');
});
