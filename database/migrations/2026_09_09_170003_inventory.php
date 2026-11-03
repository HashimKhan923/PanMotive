<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32)->nullable();
            $table->boolean('is_default')->default(false);
            $table->json('address')->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'is_default']);
        });

        Schema::create('product_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_location_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'product_variant_id', 'inventory_location_id'], 'product_stock_unique');
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('inventory_location_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('delta');
            $table->string('reason'); // sale | restock | adjustment | return | correction | transfer
            $table->string('reference')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->integer('balance_after');
            $table->timestamps();

            $table->index(['product_id', 'created_at']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('reorder_point')->nullable()->after('quantity');
            $table->unsignedInteger('reorder_qty')->nullable()->after('reorder_point');
            $table->timestamp('low_stock_notified_at')->nullable()->after('reorder_qty');
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['reorder_point', 'reorder_qty', 'low_stock_notified_at']));
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('product_stock');
        Schema::dropIfExists('inventory_locations');
    }
};
