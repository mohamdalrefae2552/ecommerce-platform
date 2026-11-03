<?php

declare(strict_types=1);

namespace App\Shared\Auth;

use DateTimeImmutable;
use Illuminate\Support\Str;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Eddsa;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Builder;
use LogicException;
use Psr\Clock\ClockInterface;

/**
 * Issues EdDSA (Ed25519) access tokens (docs/04 section 2, ADR-0003).
 * Claims: iss, aud, sub (user ULID), role (display only), iat, nbf, exp, jti;
 * header kid.
 */
final readonly class TokenIssuer
{
    public function __construct(
        private JwtKeyStore $keys,
        private JwtConfig $config,
        private ClockInterface $clock,
    ) {}

    public function issue(AuthUser $user): IssuedToken
    {
        return $this->issueAt($user, $this->clock->now());
    }

    /**
     * Issues a token as if it had been issued at $issuedAt. Used by the
     * non-production `jwt:issue-expired` command to demonstrate TOKEN_EXPIRED.
     */
    public function issueAt(AuthUser $user, DateTimeImmutable $issuedAt): IssuedToken
    {
        $secret = $this->keys->activeSecretKey();

        $token = (new Builder(new JoseEncoder, ChainedFormatter::default()))
            ->withHeader('kid', $this->keys->activeKid())
            ->issuedBy($this->config->issuer)
            ->permittedFor($this->config->audience)
            ->relatedTo($this->subject($user))
            ->identifiedBy((string) Str::ulid())
            ->issuedAt($issuedAt)
            ->canOnlyBeUsedAfter($issuedAt)
            ->expiresAt($issuedAt->modify('+'.$this->config->accessTtl.' seconds'))
            ->withClaim('role', $user->userRole()->value)
            ->getToken(new Eddsa, InMemory::base64Encoded($secret));

        return new IssuedToken($token->toString(), $this->config->accessTtl);
    }

    /**
     * @return non-empty-string
     */
    private function subject(AuthUser $user): string
    {
        $id = $user->userId();

        if ($id === '') {
            throw new LogicException('Cannot issue a token for a user without id.');
        }

        return $id;
    }
}
