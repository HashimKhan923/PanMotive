<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->foreignId('buyer_id')->constrained('users');
            $table->string('status')->default('placed'); // backed by OrderStatus enum
            $table->decimal('subtotal', 10, 2);
            $table->decimal('shipping_total', 10, 2)->default(0);
            $table->decimal('tax_total', 10, 2)->default(0);
            $table->decimal('discount_total', 10, 2)->default(0);
            $table->decimal('total', 10, 2);
            $table->string('currency', 3)->default('USD');
            $table->json('shipping_address');
            $table->json('billing_address');
            $table->string('stripe_payment_intent_id')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('auto_confirm_at')->nullable(); // delivered_at + N days, for auto-release
            $table->timestamps();
        });

        // One order splits into per-seller line items — each seller gets their own
        // sub-fulfillment, tracking, status, and payout calculation.
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users');
            $table->foreignId('product_id')->constrained();
            $table->string('product_title'); // snapshot at time of sale
            $table->string('product_sku')->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('line_total', 10, 2);
            $table->decimal('commission_rate', 5, 2); // snapshot, e.g. 10.00
            $table->decimal('platform_fee_amount', 10, 2);
            $table->decimal('seller_payout_amount', 10, 2);
            $table->string('status')->default('placed'); // per-seller sub-status
            $table->string('carrier')->nullable();
            $table->string('tracking_number')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('payout_released_at')->nullable();
            $table->timestamps();
        });

        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->text('note')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
