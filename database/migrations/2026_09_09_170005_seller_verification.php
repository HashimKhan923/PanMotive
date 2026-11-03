<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('legal_name')->nullable();
            $table->string('registered_address')->nullable();
            $table->enum('tax_id_type', ['ein', 'ssn_last4'])->nullable();
            $table->string('tax_id_last4', 4)->nullable();
            $table->string('business_doc_path')->nullable();
            $table->string('id_doc_path')->nullable();
            $table->string('address_proof_path')->nullable();
            $table->enum('status', ['unsubmitted', 'pending', 'approved', 'rejected'])->default('unsubmitted');
            $table->string('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_verifications');
    }
};
