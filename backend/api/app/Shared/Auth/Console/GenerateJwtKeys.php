<?php

declare(strict_types=1);

namespace App\Shared\Auth\Console;

use App\Shared\Auth\JwtKeyStore;
use Illuminate\Console\Command;

/**
 * `php artisan jwt:keys` (run by `make bootstrap`): creates the Ed25519
 * signing key pair in storage/keys unless one exists; `--rotate` adds a new
 * active key and keeps the old public keys for tokens still in flight.
 */
final class GenerateJwtKeys extends Command
{
    protected $signature = 'jwt:keys {--rotate : Create a new active key even if one exists}';

    protected $description = 'Generate the Ed25519 key pair that signs access tokens';

    public function handle(JwtKeyStore $keys): int
    {
        if ($keys->exists() && ! $this->option('rotate')) {
            $this->components->info('JWT keys already exist (active kid '.$keys->activeKid().'); kept.');

            return self::SUCCESS;
        }

        $this->components->info('New active JWT key: '.$keys->generate());

        return self::SUCCESS;
    }
}
