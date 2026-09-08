<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->after('total')->constrained()->nullOnDelete();
            $table->decimal('credits_used', 10, 2)->default(0)->after('discount_total');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('cashback_amount', 10, 2)->default(0)->after('seller_payout_amount');
            $table->timestamp('cashback_credited_at')->nullable()->after('payout_released_at');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['cashback_amount', 'cashback_credited_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
            $table->dropColumn('credits_used');
        });
    }
};
