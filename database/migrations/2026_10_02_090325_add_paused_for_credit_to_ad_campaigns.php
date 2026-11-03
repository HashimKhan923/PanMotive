<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Distinguishes "paused because the ad wallet ran dry" (auto-resumes on top-up)
        // from "paused by the seller" (stays paused until they resume it).
        Schema::table('ad_campaigns', function (Blueprint $table) {
            $table->boolean('paused_for_credit')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('ad_campaigns', function (Blueprint $table) {
            $table->dropColumn('paused_for_credit');
        });
    }
};
