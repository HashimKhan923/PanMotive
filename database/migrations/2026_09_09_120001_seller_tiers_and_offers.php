<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->string('tier')->default('standard')->after('status');
            $table->timestamp('tier_evaluated_at')->nullable()->after('tier');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('accepts_offers')->default(false)->after('sale_price');
            $table->decimal('min_offer_price', 10, 2)->nullable()->after('accepts_offers');
        });

        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('offer_price', 10, 2);       // per-unit
            $table->decimal('counter_price', 10, 2)->nullable();
            $table->string('message')->nullable();
            $table->enum('status', ['pending', 'countered', 'accepted', 'declined', 'expired', 'withdrawn'])->default('pending');
            $table->string('coupon_code')->nullable();   // single-use code minted on accept
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'status']);
            $table->index(['buyer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['accepts_offers', 'min_offer_price']));
        Schema::table('seller_profiles', fn (Blueprint $t) => $t->dropColumn(['tier', 'tier_evaluated_at']));
    }
};
