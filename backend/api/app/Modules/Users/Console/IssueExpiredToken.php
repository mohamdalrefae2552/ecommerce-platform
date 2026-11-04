<?php

declare(strict_types=1);

namespace App\Modules\Users\Console;

use App\Modules\Users\Models\User;
use App\Shared\Auth\JwtConfig;
use App\Shared\Auth\TokenIssuer;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Non-production demo helper (make demo-r1): prints a correctly signed
 * access token for the user whose `exp` is already in the past, to show
 * that the API answers 401 TOKEN_EXPIRED.
 */
final class IssueExpiredToken extends Command
{
    protected $signature = 'jwt:issue-expired {email : E-mail of an existing user}';

    protected $description = 'Print a validly signed but expired access token (non-production only)';

    public function handle(TokenIssuer $issuer, JwtConfig $config): int
    {
        if ($this->laravel->environment('production')) {
            $this->components->error('jwt:issue-expired is refused in production.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', Str::lower((string) $this->argument('email')))->first();

        if ($user === null) {
            $this->components->error('No such user.');

            return self::FAILURE;
        }

        $issuedAt = Carbon::now()->subSeconds($config->accessTtl + $config->leeway + 60)->toDateTimeImmutable();
        $this->line($issuer->issueAt($user, $issuedAt)->value);

        return self::SUCCESS;
    }
}
