<?php

declare(strict_types=1);

namespace App\Modules\Users\Database\Seeders;

use App\Modules\Users\Models\User;
use App\Shared\Auth\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;

/**
 * Test customers customer{N}@example.test (D-04), non-production only: the
 * register limit (5 per hour per IP) is never raised, so E2E and k6 use
 * these accounts. Idempotent.
 */
final class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('CustomerSeeder skipped: production.');

            return;
        }

        $count = Config::integer('users.seed_customers.count');
        $password = Config::string('users.seed_customers.password');

        if ($count <= 0 || mb_strlen($password) < 12) {
            $this->command?->warn('CustomerSeeder skipped: set SEED_CUSTOMER_COUNT and SEED_CUSTOMER_PASSWORD (12+ characters).');

            return;
        }

        $hash = Hash::make($password);

        for ($n = 1; $n <= $count; $n++) {
            $user = User::query()->firstOrNew(['email' => 'customer'.$n.'@example.test']);
            $user->name = 'Customer '.$n;
            $user->password = $hash;
            $user->role = Role::Customer;
            $user->save();
        }
    }
}
