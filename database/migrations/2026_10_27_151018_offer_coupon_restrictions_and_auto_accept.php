<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            // A code minted for an accepted offer is only good for that shopper, that listing,
            // and at least the quantity they negotiated for.
            $table->foreignId('user_id')->nullable()->after('seller_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('min_quantity')->nullable()->after('product_id');
        });

        Schema::table('products', function (Blueprint $table) {
            // Offers at or above this per-unit price are accepted instantly (eBay-style auto-accept).
            $table->decimal('auto_accept_offer_price', 10, 2)->nullable()->after('min_offer_price');
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn('min_quantity');
        });
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('auto_accept_offer_price'));
    }
};
