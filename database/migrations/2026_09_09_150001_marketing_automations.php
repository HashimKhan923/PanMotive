<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->enum('trigger', ['abandoned_cart', 'post_purchase', 'win_back', 'new_follower', 'price_drop']);
            $table->boolean('is_active')->default(false);
            $table->string('channel')->default('email');
            $table->unsignedSmallInteger('delay_hours')->default(4);
            $table->string('subject');
            $table->text('body');
            $table->json('settings')->nullable();   // e.g. {"lapsed_days":90, "discount_code":"WELCOME10"}
            $table->json('stats')->nullable();       // {"sent":0,"opened":0,"clicked":0,"orders":0,"revenue":0}
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->unique(['seller_id', 'trigger']);
        });

        Schema::create('automation_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketing_automation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('dedupe_key');           // "cart:12", "order:88", "follow:5", "wishlist:9:1704067200"
            $table->string('token', 40)->unique();
            $table->timestamp('opened_at')->nullable();
            $table->unsignedInteger('clicks')->default(0);
            $table->timestamp('clicked_at')->nullable();
            $table->foreignId('converted_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->decimal('conversion_value', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['marketing_automation_id', 'dedupe_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_sends');
        Schema::dropIfExists('marketing_automations');
    }
};
