<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sellers can ask Pan Motive to review a review that breaks the rules (abuse, wrong item,
        // shipping-carrier complaint) — the review stays up until an admin decides.
        Schema::table('reviews', function (Blueprint $table) {
            $table->string('report_reason', 40)->nullable();
            $table->text('report_note')->nullable();
            $table->timestamp('reported_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('reviews', fn (Blueprint $table) => $table->dropColumn(['report_reason', 'report_note', 'reported_at']));
    }
};
