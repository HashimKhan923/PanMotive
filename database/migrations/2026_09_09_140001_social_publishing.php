<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->string('platform'); // facebook|instagram|x|pinterest|tiktok|linkedin|google_business|youtube
            $table->string('handle')->nullable();
            $table->string('external_id')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->string('status')->default('connected'); // connected|expired|revoked
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['seller_id', 'platform']);
        });

        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->string('link_url')->nullable();
            $table->string('media_path')->nullable();
            $table->string('status')->default('draft'); // draft|scheduled|publishing|published|partial|failed
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'status', 'scheduled_at']);
        });

        Schema::create('social_post_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform');
            $table->string('status')->default('pending'); // pending|published|failed|skipped
            $table->string('external_id')->nullable();
            $table->string('permalink')->nullable();
            $table->text('error')->nullable();
            $table->json('metrics')->nullable(); // impressions, likes, comments, shares, clicks
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_post_targets');
        Schema::dropIfExists('social_posts');
        Schema::dropIfExists('social_accounts');
    }
};
