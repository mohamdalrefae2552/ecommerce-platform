<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Users\Models\User;
use App\Shared\Auth\Role;
use App\Shared\Auth\TokenIssuer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

/**
 * Live before/after demo of requirement 3 (`make perf-compare`): prints, as
 * one JSON line, access tokens for the dev admin and the seeded customers
 * customer1..N (D-04, D-05), issued directly with TokenIssuer — the same
 * signed EdDSA tokens POST /auth/login returns, with the same 15-minute
 * lifetime.
 *
 * Why not log in over HTTP: 60 logins take three minutes behind the per-IP
 * login limit (20/min), which is too long for a live demo and must not be
 * raised. This bypasses no check of real traffic: every request made with
 * these tokens still goes through the JWT guard, the policies and the
 * per-user rate limit. Refused in production, like the other dev helpers.
 */
final class PerfTokens extends Command
{
    protected $signature = 'app:perf-tokens {count : Seeded customers (customer1..N)}';

    protected $description = 'Print access tokens of the dev admin and customer1..N as JSON for the load test (non-production only)';

    public function handle(TokenIssuer $issuer): int
    {
        if ($this->laravel->environment('production')) {
            $this->components->error('app:perf-tokens is refused in production.');

            return self::FAILURE;
        }

        $count = (int) $this->argument('count');

        if ($count < 1 || $count > 1000) {
            $this->components->error('count must be between 1 and 1000.');

            return self::FAILURE;
        }

        $emails = array_map(static fn (int $n): string => 'customer'.$n.'@example.test', range(1, $count));
        $customers = User::query()->whereIn('email', $emails)->where('role', Role::Customer->value)->whereNull('disabled_at')->get()->keyBy('email');
        $admin = $this->admin();

        if ($customers->count() !== $count || $admin === null) {
            $this->components->error('Missing seeded customers or the dev admin: run `make bootstrap` first.');

            return self::FAILURE;
        }

        $this->line((string) json_encode([
            'admin' => $issuer->issue($admin)->value,
            'customers' => array_map(static function (string $email) use ($customers, $issuer): string {
                /** @var User $customer */
                $customer = $customers->get($email);

                return $issuer->issue($customer)->value;
            }, $emails),
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    private function admin(): ?User
    {
        $email = Config::string('users.dev_admin.email');
        $admins = User::query()->where('role', Role::Admin->value)->whereNull('disabled_at');

        return $email !== ''
            ? (clone $admins)->where('email', strtolower($email))->first() ?? $admins->orderBy('created_at')->first()
            : $admins->orderBy('created_at')->first();
    }
}
