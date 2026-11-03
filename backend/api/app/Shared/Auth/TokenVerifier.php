<?php

declare(strict_types=1);

namespace App\Shared\Auth;

use App\Shared\Http\Problems\ProblemCode;
use App\Shared\Http\Problems\ProblemException;
use DateInterval;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Eddsa;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Lcobucci\JWT\Validation\Validator;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * Verifies access tokens. The algorithm is pinned to EdDSA: any other `alg`
 * (none, HS256 with the public key, RS256...) is rejected before the
 * signature is checked. Returns the subject (user ULID) or null when the
 * token is invalid; an expired but otherwise valid token is TOKEN_EXPIRED.
 */
final readonly class TokenVerifier
{
    public function __construct(
        private JwtKeyStore $keys,
        private JwtConfig $config,
        private ClockInterface $clock,
    ) {}

    public function subject(string $jwt): ?string
    {
        if ($jwt === '') {
            return null;
        }

        try {
            $token = (new Parser(new JoseEncoder))->parse($jwt);
        } catch (Throwable) {
            return null;
        }

        if (! $token instanceof UnencryptedToken || $token->headers()->get('alg') !== 'EdDSA') {
            return null;
        }

        $kid = $token->headers()->get('kid');
        $publicKey = is_string($kid) ? $this->keys->publicKey($kid) : null;

        if ($publicKey === null) {
            return null;
        }

        $validator = new Validator;
        $authentic = $validator->validate(
            $token,
            new SignedWith(new Eddsa, InMemory::base64Encoded($publicKey)),
            new IssuedBy($this->config->issuer),
            new PermittedFor($this->config->audience),
        );

        if (! $authentic) {
            return null;
        }

        $leeway = new DateInterval('PT'.max(0, $this->config->leeway).'S');

        if ($token->isExpired($this->clock->now()->sub($leeway))) {
            throw new ProblemException(ProblemCode::TokenExpired);
        }

        if (! $validator->validate($token, new StrictValidAt($this->clock, $leeway))) {
            return null;
        }

        $subject = $token->claims()->get('sub');

        return is_string($subject) && $subject !== '' ? $subject : null;
    }
}
