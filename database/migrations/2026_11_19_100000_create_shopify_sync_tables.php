<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Connect Shopify": a seller links their Shopify store once; products are imported and then kept
 * in step by Shopify's webhooks (create / update / delete / stock).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('shop_domain')->unique();           // my-store.myshopify.com
            $table->string('shop_name')->nullable();
            $table->text('access_token')->nullable();           // encrypted
            $table->text('refresh_token')->nullable();          // encrypted (expiring offline tokens)
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('refresh_expires_at')->nullable();
            $table->string('scopes')->nullable();
            $table->string('status', 20)->default('connected'); // connected | disconnected | error
            $table->string('last_error', 500)->nullable();
            $table->string('sync_status', 20)->default('idle');  // idle | running
            $table->unsignedInteger('sync_total')->default(0);
            $table->unsignedInteger('sync_done')->default(0);
            $table->timestamp('last_full_sync_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shopify_product_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shopify_connection_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('shopify_product_id');
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('payload_hash', 40)->nullable();     // skip identical updates
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['shopify_connection_id', 'shopify_product_id'], 'shopify_link_unique');
            $table->index('product_id');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedBigInteger('shopify_variant_id')->nullable()->index()->after('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', fn (Blueprint $t) => $t->dropColumn('shopify_variant_id'));
        Schema::dropIfExists('shopify_product_links');
        Schema::dropIfExists('shopify_connections');
    }
};
