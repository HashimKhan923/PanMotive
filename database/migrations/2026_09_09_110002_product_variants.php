<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_variants')) {
            Schema::create('product_variants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->string('label');                 // "Front · Ceramic"
                $table->json('options');                 // {"Position":"Front","Material":"Ceramic"}
                $table->string('sku')->nullable();
                $table->decimal('price', 10, 2)->nullable();       // null → inherit product price
                $table->decimal('sale_price', 10, 2)->nullable();
                $table->unsignedInteger('quantity')->default(0);
                $table->decimal('weight_lbs', 8, 2)->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['product_id', 'is_active']);
            });
        }

        if (! Schema::hasColumn('cart_items', 'product_variant_id')) {
            // The composite unique backs the cart_id FK on MariaDB — drop the FK first.
            Schema::table('cart_items', fn (Blueprint $t) => $t->dropForeign(['cart_id']));

            Schema::table('cart_items', function (Blueprint $table) {
                $table->dropUnique(['cart_id', 'product_id']);
                $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
                $table->unique(['cart_id', 'product_id', 'product_variant_id'], 'cart_items_line_unique');
                $table->foreign('cart_id')->references('id')->on('carts')->cascadeOnDelete();
            });
        }

        if (! Schema::hasColumn('order_items', 'product_variant_id')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
                $table->string('variant_label')->nullable()->after('product_title');
            });
        }
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_variant_id');
            $table->dropColumn('variant_label');
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropForeign(['cart_id']);
            $table->dropUnique('cart_items_line_unique');
            $table->dropConstrainedForeignId('product_variant_id');
            $table->unique(['cart_id', 'product_id']);
            $table->foreign('cart_id')->references('id')->on('carts')->cascadeOnDelete();
        });

        Schema::dropIfExists('product_variants');
    }
};
