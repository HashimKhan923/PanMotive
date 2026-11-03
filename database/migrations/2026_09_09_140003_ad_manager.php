<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->enum('objective', ['traffic', 'sales', 'awareness'])->default('sales');
            $table->enum('status', ['draft', 'active', 'paused', 'ended'])->default('active');
            $table->decimal('daily_budget', 10, 2);
            $table->decimal('total_spend', 12, 2)->default(0);
            $table->decimal('spent_today', 10, 2)->default(0);
            $table->date('spend_day')->nullable();
            $table->json('targeting');   // {categories:[], vehicle_make_ids:[], keywords:[], placements:[]}
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'seller_id']);
        });

        Schema::create('ad_creatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('headline')->nullable();
            $table->string('primary_text', 300)->nullable();
            $table->string('image_path')->nullable();
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedInteger('attributed_orders')->default(0);
            $table->decimal('attributed_revenue', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_creatives');
        Schema::dropIfExists('ad_campaigns');
    }
};
