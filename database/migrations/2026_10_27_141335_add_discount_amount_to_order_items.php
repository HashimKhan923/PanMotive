<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // Seller-funded discount on this line (bundle savings, seller coupons).
            // line_total is what the buyer actually pays for the line AFTER this discount,
            // so commission and the seller's payout are calculated on the discounted amount.
            $table->decimal('discount_amount', 10, 2)->default(0)->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('discount_amount');
        });
    }
};
