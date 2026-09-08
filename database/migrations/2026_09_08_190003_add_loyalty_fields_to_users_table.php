<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('loyalty_tier')->default('bronze')->after('wallet_balance');
            $table->decimal('lifetime_spend', 10, 2)->default(0)->after('loyalty_tier');
            $table->string('referral_code')->unique()->nullable()->after('lifetime_spend');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['loyalty_tier', 'lifetime_spend', 'referral_code']);
        });
    }
};
