<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->unsignedTinyInteger('handling_days')->default(2);
            $table->decimal('flat_rate', 10, 2)->nullable();          // overrides weight-tiered pricing
            $table->decimal('free_over', 10, 2)->nullable();          // per-shipment free-shipping threshold
            $table->json('region_surcharges')->nullable();            // { "AK": 9.99, "HI": 9.99, "PR": 14.99 }
            $table->boolean('no_ship_alaska_hawaii')->default(false);
            $table->timestamps();

            $table->index(['seller_id', 'is_default']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('shipping_policy_id')->nullable()->after('weight_lbs')->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('handling_days')->nullable()->after('shipping_policy_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shipping_policy_id');
            $table->dropColumn('handling_days');
        });
        Schema::dropIfExists('shipping_policies');
    }
};
