<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "List like this" — a saved starting point for new listings.
        Schema::create('product_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->json('payload');
            $table->timestamps();
        });

        // Automatic repricing rules — one per listing.
        Schema::create('repricing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->enum('strategy', ['match_lowest', 'beat_lowest', 'fixed_above_lowest']);
            $table->decimal('amount', 10, 2)->default(0);   // beat by / stay above by
            $table->decimal('floor_price', 10, 2);           // never price under this
            $table->decimal('ceiling_price', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_result')->nullable();
            $table->timestamps();
        });

        // Bundle deals — buy these together, save.
        Schema::create('bundles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->enum('discount_type', ['percentage', 'fixed']);
            $table->decimal('discount_value', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('bundle_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bundle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->unique(['bundle_id', 'product_id']);
        });

        // Tiered volume discounts on a single product.
        Schema::create('volume_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('min_quantity');
            $table->enum('discount_type', ['percentage', 'fixed_per_unit']);
            $table->decimal('discount_value', 10, 2);
            $table->timestamps();
            $table->unique(['product_id', 'min_quantity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('volume_discounts');
        Schema::dropIfExists('bundle_items');
        Schema::dropIfExists('bundles');
        Schema::dropIfExists('repricing_rules');
        Schema::dropIfExists('product_templates');
    }
};
