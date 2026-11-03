<?php

declare(strict_types=1);

namespace App\Shared\Auth;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Ed25519 signing keys in storage/keys (a volume, never committed; docs/04
 * section 2). Layout: `<kid>.secret` and `<kid>.public` (base64, mode 0600)
 * plus `active-kid` naming the signing key. Old public keys stay readable so
 * tokens signed before a rotation remain verifiable until they expire.
 */
final readonly class JwtKeyStore
{
    private const string ACTIVE = 'active-kid';

    private const string KID_PATTERN = '/^[A-Za-z0-9-]{1,64}$/';

    public function __construct(private string $directory) {}

    public function exists(): bool
    {
        return is_file($this->directory.'/'.self::ACTIVE);
    }

    /**
     * Creates a new key pair and makes it the active one; returns its kid.
     */
    public function generate(): string
    {
        if (! is_dir($this->directory) && ! mkdir($this->directory, 0700, true) && ! is_dir($this->directory)) {
            throw new RuntimeException('Cannot create the JWT key directory.');
        }

        $kid = Carbon::now()->format('Y-m').'-'.Str::lower(Str::random(8));
        $pair = sodium_crypto_sign_keypair();

        $this->write($kid.'.secret', base64_encode(sodium_crypto_sign_secretkey($pair)));
        $this->write($kid.'.public', base64_encode(sodium_crypto_sign_publickey($pair)));
        $this->write(self::ACTIVE, $kid);

        return $kid;
    }

    /**
     * @return non-empty-string
     */
    public function activeKid(): string
    {
        $kid = $this->read(self::ACTIVE);

        if (preg_match(self::KID_PATTERN, $kid) !== 1) {
            throw new RuntimeException('The active JWT key id is invalid.');
        }

        return $kid;
    }

    /**
     * Base64 Ed25519 secret key of the active kid.
     *
     * @return non-empty-string
     */
    public function activeSecretKey(): string
    {
        return $this->read($this->activeKid().'.secret');
    }

    /**
     * Base64 Ed25519 public key for a kid, or null when the kid is unknown.
     *
     * @return non-empty-string|null
     */
    public function publicKey(string $kid): ?string
    {
        if (preg_match(self::KID_PATTERN, $kid) !== 1 || ! is_file($this->directory.'/'.$kid.'.public')) {
            return null;
        }

        return $this->read($kid.'.public');
    }

    /**
     * @return non-empty-string
     */
    private function read(string $file): string
    {
        $path = $this->directory.'/'.$file;
        $content = is_file($path) ? trim((string) file_get_contents($path)) : '';

        if ($content === '') {
            throw new RuntimeException('JWT keys are missing: run `php artisan jwt:keys`.');
        }

        return $content;
    }

    private function write(string $file, string $content): void
    {
        $path = $this->directory.'/'.$file;

        if (file_put_contents($path, $content, LOCK_EX) === false || ! chmod($path, 0600)) {
            throw new RuntimeException('Cannot write the JWT key '.$file.'.');
        }
    }
}
