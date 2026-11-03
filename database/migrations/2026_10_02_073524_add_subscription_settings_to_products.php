<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Seller's control over Subscribe & Save per listing. Defaults preserve the
        // previous behaviour (on, 5%) so existing listings and subscriptions are unaffected.
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('subscription_enabled')->default(true)->after('accepts_offers');
            $table->decimal('subscription_discount_pct', 4, 2)->default(5)->after('subscription_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['subscription_enabled', 'subscription_discount_pct']);
        });
    }
};
