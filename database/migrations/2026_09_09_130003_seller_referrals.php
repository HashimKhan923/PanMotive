<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referred_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('code', 16)->unique();
            $table->enum('status', ['pending', 'active', 'expired'])->default('pending');
            $table->unsignedTinyInteger('reward_months')->default(3);
            $table->decimal('commission_discount_pct', 4, 2)->default(2.0);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['referrer_id', 'status']);
        });

        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->decimal('referral_bonus_pct', 4, 2)->nullable()->after('tier_evaluated_at');
            $table->timestamp('referral_bonus_until')->nullable()->after('referral_bonus_pct');
        });
    }

    public function down(): void
    {
        Schema::table('seller_profiles', fn (Blueprint $t) => $t->dropColumn(['referral_bonus_pct', 'referral_bonus_until']));
        Schema::dropIfExists('seller_referrals');
    }
};
