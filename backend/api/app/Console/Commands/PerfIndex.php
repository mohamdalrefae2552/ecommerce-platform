<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Live before/after demo of requirement 3 (`make perf-compare`): removes
 * (`off`) or restores (`on`) the index added by the requirement 3 migration
 * (2026_11_06_000100_add_orders_created_at_index), so the "before" run
 * really reads the orders table the way it did before the optimisation.
 * Idempotent (IF EXISTS / IF NOT EXISTS) and refused in production: the
 * migration stays the source of truth, this only toggles it in development.
 */
final class PerfIndex extends Command
{
    /** Name Laravel gives the index of `$table->index(['created_at', 'id'])` on orders. */
    public const string INDEX = 'orders_created_at_id_index';

    protected $signature = 'app:perf-index {state : on or off}';

    protected $description = 'Drop (off) or recreate (on) the requirement 3 orders index for the live before/after demo (non-production only)';

    public function handle(): int
    {
        if ($this->laravel->environment('production')) {
            $this->components->error('app:perf-index is refused in production.');

            return self::FAILURE;
        }

        $state = $this->argument('state');

        if ($state !== 'on' && $state !== 'off') {
            $this->components->error('state must be "on" or "off".');

            return self::FAILURE;
        }

        DB::statement($state === 'on'
            ? 'CREATE INDEX IF NOT EXISTS '.self::INDEX.' ON orders (created_at, id)'
            : 'DROP INDEX IF EXISTS '.self::INDEX);
        DB::statement('ANALYZE orders');

        $this->components->info(self::INDEX.' is '.($state === 'on' ? 'present' : 'dropped').'.');

        return self::SUCCESS;
    }
}
