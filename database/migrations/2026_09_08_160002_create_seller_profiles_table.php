<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('business_name');
            $table->string('business_type'); // sole_prop, llc, corp
            $table->string('ein_encrypted')->nullable();
            $table->string('stripe_account_id')->nullable()->index();
            $table->boolean('stripe_onboarding_complete')->default(false);
            $table->enum('status', ['pending', 'approved', 'suspended', 'rejected'])->default('pending');
            $table->enum('kyc_status', ['unsubmitted', 'pending', 'verified', 'rejected'])->default('unsubmitted');
            $table->decimal('commission_rate_override', 5, 2)->nullable(); // null = use platform default
            $table->decimal('rating', 3, 2)->default(0);
            $table->unsignedInteger('rating_count')->default(0);
            $table->json('shipping_settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_profiles');
    }
};
