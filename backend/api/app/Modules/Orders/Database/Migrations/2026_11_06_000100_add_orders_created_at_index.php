<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Requirement 3 (docs/performance/explain.md, Q2): the admin's list of every
// order is sorted by (created_at desc, id desc) without a user or status
// filter. No index started with created_at, so every page read the whole
// table (Seq Scan of 200,000 rows + top-N sort, ~32 ms). With this index
// PostgreSQL walks it backwards and stops after offset + limit rows.
// The customer list (user_id, created_at) and the status filter
// (status, created_at) already had their indexes.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['created_at', 'id']);
        });
    }
};
