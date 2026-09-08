<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->index(['seller_id', 'status']); // seller dashboard / orders queries
            $table->index('status');                // admin dispute / finance queries
        });

        Schema::table('products', function (Blueprint $table) {
            $table->index(['status', 'category_id']); // browse filtering
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['seller_id', 'status']);
            $table->dropIndex(['status']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['status', 'category_id']);
        });
    }
};
