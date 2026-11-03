<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->string('carrier');
            $table->string('service')->nullable();
            $table->string('tracking_number');
            $table->decimal('rate', 8, 2)->default(0);
            $table->decimal('retail_rate', 8, 2)->nullable();  // what it'd cost off-platform
            $table->string('label_url')->nullable();
            $table->string('provider')->default('mock');       // easypost | mock
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'created_at']);
        });

        // Which order items a single label / shipment covers (consolidated shipping).
        Schema::create('shipment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_label_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->unique('order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_items');
        Schema::dropIfExists('shipping_labels');
    }
};
