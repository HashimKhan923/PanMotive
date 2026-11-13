<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drag-and-drop email campaigns: the block design of each campaign, sellers' saved templates,
 * images they upload, every tracked click (with which link and whether it looked like a bot),
 * bounces and complaints, and each store's email brand (colour, button style).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_campaigns', function (Blueprint $table) {
            $table->json('design')->nullable();
        });

        Schema::create('campaign_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 80);
            $table->json('design');
            $table->timestamps();
        });

        Schema::create('campaign_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->string('path');
            $table->unsignedInteger('size')->default(0);
            $table->timestamps();
        });

        Schema::create('campaign_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketing_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_recipient_id')->constrained()->cascadeOnDelete();
            $table->string('link_key', 40);
            $table->string('url', 500);
            $table->boolean('is_bot')->default(false);
            $table->dateTime('clicked_at');
            $table->index(['marketing_campaign_id', 'link_key']);
        });

        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->dateTime('bounced_at')->nullable();
            $table->dateTime('complained_at')->nullable();
        });

        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->json('email_brand')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('seller_profiles', fn (Blueprint $t) => $t->dropColumn('email_brand'));
        Schema::table('campaign_recipients', fn (Blueprint $t) => $t->dropColumn(['bounced_at', 'complained_at']));
        Schema::dropIfExists('campaign_clicks');
        Schema::dropIfExists('campaign_images');
        Schema::dropIfExists('campaign_templates');
        Schema::table('marketing_campaigns', fn (Blueprint $t) => $t->dropColumn('design'));
    }
};
