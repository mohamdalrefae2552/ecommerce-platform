<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Requirement 4: notifications are created by a queue worker, and a message
// can be delivered more than once. message_id (unique) makes the insert
// idempotent; mailed_at records that the e-mail was sent.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->ulid('message_id')->nullable()->unique();
            $table->timestampTz('mailed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropColumn(['message_id', 'mailed_at']);
        });
    }
};
