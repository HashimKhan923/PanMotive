<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['cashback', 'referral_bonus', 'redeemed', 'admin_adjustment', 'refund_reversal']);
            $table->decimal('amount', 10, 2); // positive = credit added, negative = spent
            $table->decimal('balance_after', 10, 2);
            $table->string('description');
            $table->nullableMorphs('source'); // polymorphic: Order, Referral, etc.
            $table->timestamps();
        });

        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referred_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('code');
            $table->enum('status', ['pending', 'completed'])->default('pending');
            $table->decimal('referrer_reward', 10, 2)->default(10.00);
            $table->decimal('referred_reward', 10, 2)->default(10.00);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['referred_user_id', 'status']);
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->enum('type', ['percentage', 'fixed']);
            $table->decimal('value', 10, 2);
            $table->decimal('min_order_total', 10, 2)->nullable();
            $table->decimal('max_discount_amount', 10, 2)->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('usage_limit_per_user')->nullable();
            $table->unsignedInteger('times_used')->default(0);
            $table->foreignId('seller_id')->nullable()->constrained('users')->cascadeOnDelete(); // null = platform-wide
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('coupon_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('order_id')->constrained();
            $table->decimal('discount_amount', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_usages');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('referrals');
        Schema::dropIfExists('credit_transactions');
    }
};
