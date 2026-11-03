<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Instant-payout advances: platform fronts the payout on ship and
        // recoups it when the normal escrow release would have fired.
        Schema::create('payout_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('gross', 10, 2);        // seller_payout_amount
            $table->decimal('fee', 10, 2);          // advance fee
            $table->decimal('net_paid', 10, 2);     // gross − fee, transferred now
            $table->string('stripe_transfer_id')->nullable();
            $table->enum('status', ['advanced', 'recouped', 'clawed_back'])->default('advanced');
            $table->timestamp('recouped_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_advances');
    }
};
