<?php

declare(strict_types=1);

namespace App\Shared\Auth;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;

/**
 * Typed view of config/jwt.php; refuses an incomplete configuration.
 */
final readonly class JwtConfig
{
    /**
     * @param  non-empty-string  $issuer
     * @param  non-empty-string  $audience
     * @param  positive-int  $accessTtl
     * @param  non-negative-int  $leeway
     * @param  positive-int  $refreshTtlDays
     * @param  non-empty-string  $keysPath
     */
    public function __construct(
        public string $issuer,
        public string $audience,
        public int $accessTtl,
        public int $leeway,
        public int $refreshTtlDays,
        public string $keysPath,
    ) {}

    public static function fromRepository(Repository $config): self
    {
        $issuer = $config->get('jwt.issuer');
        $audience = $config->get('jwt.audience');
        $keysPath = $config->get('jwt.keys_path');
        $accessTtl = $config->get('jwt.access_ttl');
        $leeway = $config->get('jwt.leeway');
        $refreshTtlDays = $config->get('jwt.refresh_ttl_days');

        if (! is_string($issuer) || $issuer === '' || ! is_string($audience) || $audience === '' || ! is_string($keysPath) || $keysPath === ''
            || ! is_int($accessTtl) || $accessTtl < 1 || ! is_int($leeway) || $leeway < 0 || ! is_int($refreshTtlDays) || $refreshTtlDays < 1) {
            throw new RuntimeException('Incomplete JWT configuration (JWT_* environment variables).');
        }

        return new self($issuer, $audience, $accessTtl, $leeway, $refreshTtlDays, $keysPath);
    }
}
