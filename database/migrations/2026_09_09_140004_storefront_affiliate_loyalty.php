<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->json('theme')->nullable()->after('featured_product_ids');
            $table->json('loyalty')->nullable()->after('theme');
        });

        Schema::create('affiliate_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('is_active')->default(false);
            $table->decimal('commission_pct', 5, 2)->default(8);
            $table->unsignedInteger('cookie_days')->default(30);
            $table->boolean('auto_approve')->default(true);
            $table->text('terms')->nullable();
            $table->timestamps();
        });

        Schema::create('affiliates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_program_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('code', 16)->unique();
            $table->string('channel')->nullable(); // "Instagram", "YouTube", blog...
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedInteger('conversions')->default(0);
            $table->decimal('earnings', 12, 2)->default(0);
            $table->decimal('paid_out', 12, 2)->default(0);
            $table->timestamps();

            $table->index(['affiliate_program_id', 'status']);
        });

        Schema::create('affiliate_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->decimal('order_value', 12, 2);
            $table->decimal('commission', 12, 2);
            $table->enum('status', ['pending', 'approved', 'paid', 'reversed'])->default('pending');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['affiliate_id', 'order_id']);
        });

        Schema::create('store_loyalty_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('points')->default(0);
            $table->unsignedBigInteger('lifetime_points')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'seller_id']);
        });

        Schema::create('store_loyalty_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_loyalty_account_id')->constrained()->cascadeOnDelete();
            $table->integer('delta');
            $table->unsignedBigInteger('balance_after');
            $table->string('reason');
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_loyalty_ledger');
        Schema::dropIfExists('store_loyalty_accounts');
        Schema::dropIfExists('affiliate_conversions');
        Schema::dropIfExists('affiliates');
        Schema::dropIfExists('affiliate_programs');
        Schema::table('seller_profiles', fn (Blueprint $t) => $t->dropColumn(['theme', 'loyalty']));
    }
};
