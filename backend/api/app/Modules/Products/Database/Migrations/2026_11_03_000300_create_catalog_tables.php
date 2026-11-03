<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Catalogue of the Products module. No quantity column: stock lives only in
// the inventory service (CLAUDE.md rule 5).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('slug', 64)->unique();
            $table->string('name', 100);
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 120);
            $table->string('description', 2000)->default('');
            $table->bigInteger('price_minor');
            $table->char('currency', 3)->default('SYP');
            $table->foreignUlid('category_id')->constrained('categories');
            $table->string('image', 40);
            $table->timestampsTz();
            $table->index(['created_at', 'id']);
            $table->index('category_id');
        });

        DB::statement('ALTER TABLE products ADD CONSTRAINT products_price_check CHECK (price_minor > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
