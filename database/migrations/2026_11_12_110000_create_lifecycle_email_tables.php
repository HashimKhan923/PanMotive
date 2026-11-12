<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lifecycle emails: "email me when it's back" requests, and markers so each automatic email
 * (browse reminder, add-your-car tip, did-it-fit check, win-back) goes out at most once per
 * person or order in its window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->dateTime('notified_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'product_id']);
            $table->index(['product_id', 'notified_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dateTime('browse_reminder_sent_at')->nullable();
            $table->dateTime('garage_tip_sent_at')->nullable();
            $table->dateTime('winback_sent_at')->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dateTime('fit_check_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_alerts');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['browse_reminder_sent_at', 'garage_tip_sent_at', 'winback_sent_at']));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('fit_check_sent_at'));
    }
};
