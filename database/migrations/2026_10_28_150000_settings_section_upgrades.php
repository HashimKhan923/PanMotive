<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_profiles', function (Blueprint $table) {
            // Where parcels ship from — required to buy real carrier labels.
            $table->json('ship_from')->nullable();
            // Seller return policy on top of the platform minimum (30 days).
            $table->unsignedSmallInteger('return_window_days')->default(30);
            $table->string('return_shipping', 12)->default('buyer'); // buyer | seller
            // Time away: scheduled, optional pause on new orders, auto-reply to messages.
            $table->timestamp('vacation_until')->nullable();
            $table->boolean('vacation_pause_sales')->default(false);
        });

        // Real labels are paid by the seller: the cost comes out of that order's payout.
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('label_fee', 10, 2)->default(0)->after('affiliate_fee');
        });

        Schema::table('shipping_labels', function (Blueprint $table) {
            $table->string('external_id')->nullable()->after('provider');
        });

        // Recent webhook attempts, so sellers can debug their endpoint.
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->string('event', 40);
            $table->string('status', 40);          // HTTP status or "error"/"blocked"
            $table->boolean('success')->default(false);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['webhook_endpoint_id', 'created_at']);
        });

        // A buyer gets the vacation auto-reply once per conversation.
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('away_reply_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', fn (Blueprint $t) => $t->dropColumn('away_reply_sent_at'));
        Schema::dropIfExists('webhook_deliveries');
        Schema::table('shipping_labels', fn (Blueprint $t) => $t->dropColumn('external_id'));
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn('label_fee'));
        Schema::table('seller_profiles', fn (Blueprint $t) => $t->dropColumn(['ship_from', 'return_window_days', 'return_shipping', 'vacation_until', 'vacation_pause_sales']));
    }
};
