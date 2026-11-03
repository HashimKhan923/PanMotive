<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_violations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->string('type');                       // late_shipment | counterfeit | prohibited_item | policy_breach | ...
            $table->enum('severity', ['notice', 'warning', 'severe'])->default('warning');
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['active', 'appealed', 'lifted', 'upheld'])->default('active');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('appeal_text')->nullable();
            $table->timestamp('appeal_submitted_at')->nullable();
            $table->foreignId('appeal_decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('appeal_decided_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'status']);
        });

        Schema::create('buyer_claims', function (Blueprint $table) {
            $table->id();
            $table->string('claim_number')->unique();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->enum('reason', ['not_received', 'not_as_described', 'damaged', 'no_seller_response']);
            $table->text('detail')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->enum('status', ['open', 'seller_responded', 'escalated', 'granted', 'denied', 'withdrawn'])->default('open');
            $table->text('seller_response')->nullable();
            $table->timestamp('seller_responded_at')->nullable();
            $table->timestamp('respond_by')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('resolution_note')->nullable();
            $table->timestamps();

            $table->index(['seller_id', 'status']);
            $table->index(['buyer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_claims');
        Schema::dropIfExists('policy_violations');
    }
};
