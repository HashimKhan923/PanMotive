<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supply Network: a seller (supplier) lets other sellers (resellers) list its products in their own
 * store. The supplier keeps the stock and ships; the reseller earns a margin; Panmotive splits the
 * money at payout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->boolean('supply_enabled')->default(false);
            $table->decimal('supply_margin_pct', 5, 2)->default(20);
            $table->boolean('supply_auto_approve')->default(false);
            $table->string('supply_note', 500)->nullable();
        });

        Schema::create('supply_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reseller_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('pending'); // pending | approved | declined | ended
            $table->decimal('margin_pct', 5, 2);
            $table->string('message', 500)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['supplier_id', 'reseller_id']);
            $table->index(['reseller_id', 'status']);
        });

        Schema::table('products', function (Blueprint $table) {
            // A reseller's listing of a supplier's product. The supplier's row is the real stock.
            $table->foreignId('source_product_id')->nullable()->after('seller_id')->constrained('products')->nullOnDelete();
            // A supplier can keep single products out of the network.
            $table->boolean('supply_excluded')->default(false);
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->foreignId('source_variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
        });

        Schema::table('order_items', function (Blueprint $table) {
            // On a resold line the supplier is the seller (ships, gets paid); the reseller earns the margin.
            $table->foreignId('reseller_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('listing_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->decimal('reseller_margin', 10, 2)->default(0);
            $table->decimal('reseller_margin_pct', 5, 2)->nullable();
            $table->timestamp('reseller_paid_at')->nullable();
            $table->string('reseller_transfer_id')->nullable();
            $table->index('reseller_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reseller_id');
            $table->dropConstrainedForeignId('listing_product_id');
            $table->dropColumn(['reseller_margin', 'reseller_margin_pct', 'reseller_paid_at', 'reseller_transfer_id']);
        });
        Schema::table('product_variants', fn (Blueprint $t) => $t->dropConstrainedForeignId('source_variant_id'));
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_product_id');
            $table->dropColumn('supply_excluded');
        });
        Schema::dropIfExists('supply_connections');
        Schema::table('seller_profiles', fn (Blueprint $t) => $t->dropColumn(['supply_enabled', 'supply_margin_pct', 'supply_auto_approve', 'supply_note']));
    }
};
