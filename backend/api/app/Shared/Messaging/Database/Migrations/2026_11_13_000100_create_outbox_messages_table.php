<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Transactional outbox (requirement 4, ADR-0015): an event is written here in
// the SAME transaction as the business change, then published to RabbitMQ.
// `id` is the message id consumers deduplicate on.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('type', 64);
            $table->jsonb('payload');
            $table->timestampTz('created_at');
            $table->timestampTz('published_at')->nullable();
        });

        // The relay only ever looks for rows not yet published.
        DB::statement('CREATE INDEX outbox_messages_unpublished_index ON outbox_messages (created_at) WHERE published_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
