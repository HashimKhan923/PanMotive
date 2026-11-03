<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel'); // email | sms
            $table->string('name');
            $table->string('subject')->nullable();
            $table->string('preview')->nullable();
            $table->text('body');
            $table->json('audience');   // {"type":"repeat_customers"} etc.
            $table->string('status')->default('draft'); // draft|scheduled|sending|sent|failed
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->json('stats')->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'status', 'scheduled_at']);
        });

        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketing_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 40)->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->unsignedInteger('clicks')->default(0);
            $table->timestamp('clicked_at')->nullable();
            $table->foreignId('converted_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->decimal('conversion_value', 10, 2)->default(0);
            $table->string('failure')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'clicked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('marketing_campaigns');
    }
};
