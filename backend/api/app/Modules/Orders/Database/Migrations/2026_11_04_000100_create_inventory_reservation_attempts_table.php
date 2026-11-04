<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Journal of the order-creation saga (docs/05 section 3). An attempt is
// committed as PENDING before ReserveStock is called, so a reservation is
// never lost even if the PHP worker dies right after it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_reservation_attempts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->uuid('reservation_id')->unique();
            $table->ulid('user_id');
            $table->uuid('idempotency_key')->nullable();
            $table->string('state', 16);
            $table->ulid('order_id')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestampsTz();
            $table->index(['state', 'created_at']);
        });

        DB::statement("ALTER TABLE inventory_reservation_attempts ADD CONSTRAINT inventory_reservation_attempts_state_check CHECK (state IN ('PENDING', 'CONFIRMED', 'REJECTED', 'RELEASED', 'RELEASE_PENDING'))");

        Schema::table('orders', function (Blueprint $table): void {
            $table->uuid('reservation_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('reservation_id');
        });
        Schema::dropIfExists('inventory_reservation_attempts');
    }
};
