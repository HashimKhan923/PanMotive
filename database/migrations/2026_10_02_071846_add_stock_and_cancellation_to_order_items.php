<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            // Set when this line's quantity was taken out of stock; cleared when it's
            // put back. Makes commit/release idempotent (webhooks retry, buttons double-click).
            $table->timestamp('stock_committed_at')->nullable()->after('cashback_credited_at');
            $table->timestamp('cancelled_at')->nullable()->after('stock_committed_at');
            $table->string('cancel_reason', 40)->nullable()->after('cancelled_at');
            $table->string('cancel_note', 500)->nullable()->after('cancel_reason');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['stock_committed_at', 'cancelled_at', 'cancel_reason', 'cancel_note']);
        });
    }
};
