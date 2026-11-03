<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A seller's private CRM notes + tags on one of their customers. Never shown to the buyer.
        Schema::create('seller_customer_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();

            $table->unique(['seller_id', 'buyer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_customer_notes');
    }
};
