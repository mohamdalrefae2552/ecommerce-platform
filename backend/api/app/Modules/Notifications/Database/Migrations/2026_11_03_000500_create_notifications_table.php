<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// In-app notifications. user_id and order_id are plain ULIDs: users and
// orders belong to other modules (no foreign key across modules).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('user_id');
            $table->string('type', 32);
            $table->ulid('order_id');
            $table->string('order_status', 16);
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('created_at');
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
