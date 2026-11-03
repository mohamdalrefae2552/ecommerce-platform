<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 100);
            $table->string('email', 254)->unique();
            $table->string('password');
            $table->string('role', 16)->default('customer');
            $table->timestampTz('disabled_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('customer', 'admin'))");
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_lower_check CHECK (email = lower(email))');
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
