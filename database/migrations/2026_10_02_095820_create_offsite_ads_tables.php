<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_profiles', function (Blueprint $table) {
            // Etsy model: everyone is in by default; small sellers may opt out.
            $table->boolean('offsite_ads_opt_out')->default(false);
        });

        Schema::table('order_items', function (Blueprint $table) {
            // This line's share of an Offsite Ads fee — already deducted from seller_payout_amount.
            $table->decimal('offsite_ad_fee', 10, 2)->default(0)->after('platform_fee_amount');
        });

        // A buyer arriving on a listing from one of our offsite ads.
        Schema::create('offsite_ad_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel', 20);
            $table->string('visitor_key', 64);
            $table->timestamps();

            $table->index(['seller_id', 'created_at']);
            $table->index(['channel', 'created_at']);
        });

        // An order (per seller) credited to an offsite ad click, and the fee charged for it.
        Schema::create('offsite_ad_attributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('offsite_ad_click_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 20);
            $table->timestamp('clicked_at');
            $table->decimal('attributed_sales', 10, 2);
            $table->decimal('fee_rate', 5, 2);
            $table->decimal('fee_amount', 10, 2);
            $table->timestamps();

            $table->unique(['order_id', 'seller_id']);
            $table->index(['seller_id', 'created_at']);
        });

        // What Pan Motive actually paid Meta/Google each month — entered by admin, for ROI.
        Schema::create('offsite_ad_spend', function (Blueprint $table) {
            $table->id();
            $table->date('month');
            $table->string('channel', 20);
            $table->decimal('amount', 10, 2);
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['month', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offsite_ad_spend');
        Schema::dropIfExists('offsite_ad_attributions');
        Schema::dropIfExists('offsite_ad_clicks');
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn('offsite_ad_fee'));
        Schema::table('seller_profiles', fn (Blueprint $t) => $t->dropColumn('offsite_ads_opt_out'));
    }
};
