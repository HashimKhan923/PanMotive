<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email groundwork: which optional emails a person has switched off, and markers so the
 * order receipt and the unpaid-order reminder are each sent once, however many times
 * Stripe repeats a webhook or the hourly job runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('email_opt_outs')->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dateTime('confirmation_sent_at')->nullable();
            $table->dateTime('payment_reminder_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('email_opt_outs'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['confirmation_sent_at', 'payment_reminder_sent_at']));
    }
};
