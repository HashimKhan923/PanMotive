<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buyer growth: Pan Motive Plus membership, wishlist price-drop tracking, and
 * Subscribe & Save recurring orders. The recurring-order table is named
 * `product_subscriptions` to avoid colliding with Cashier's `subscriptions`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'plus_until')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('plus_until')->nullable()->after('lifetime_spend');
                $table->boolean('plus_auto_renew')->default(true)->after('plus_until');
            });
        }

        if (! Schema::hasColumn('wishlist_items', 'price_at_add')) {
            Schema::table('wishlist_items', function (Blueprint $table) {
                $table->decimal('price_at_add', 12, 2)->nullable()->after('product_id');
                $table->timestamp('price_drop_notified_at')->nullable()->after('price_at_add');
            });
        }

        if (! Schema::hasTable('product_subscriptions')) {
            Schema::create('product_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_variant_id')->nullable()->constrained()->nullOnDelete();
                $table->unsignedSmallInteger('quantity')->default(1);
                $table->unsignedSmallInteger('interval_days')->default(30);
                $table->decimal('discount_pct', 5, 2)->default(5);
                $table->enum('status', ['active', 'paused', 'cancelled'])->default('active');
                $table->timestamp('next_run_at')->nullable();
                $table->foreignId('last_order_id')->nullable()->constrained('orders')->nullOnDelete();
                $table->unsignedInteger('orders_count')->default(0);
                $table->json('shipping_address')->nullable();
                $table->timestamps();

                $table->index(['status', 'next_run_at']);
            });
        }

        if (! Schema::hasColumn('orders', 'subscription_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->foreignId('subscription_id')->nullable()->after('coupon_id')
                    ->constrained('product_subscriptions')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('orders', 'subscription_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropConstrainedForeignId('subscription_id');
            });
        }
        Schema::dropIfExists('product_subscriptions');
        if (Schema::hasColumn('wishlist_items', 'price_at_add')) {
            Schema::table('wishlist_items', function (Blueprint $table) {
                $table->dropColumn(['price_at_add', 'price_drop_notified_at']);
            });
        }
        if (Schema::hasColumn('users', 'plus_until')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn(['plus_until', 'plus_auto_renew']);
            });
        }
    }
};
