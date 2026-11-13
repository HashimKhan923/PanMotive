<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Social studio upgrade: a caption per channel (each app gets its own wording), the store's
 * social settings (auto-queue and link-in-bio page), and a view counter for that page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_post_targets', function (Blueprint $table) {
            $table->text('caption')->nullable();
        });

        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->json('social_settings')->nullable();
            $table->unsignedInteger('bio_views')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('seller_profiles', fn (Blueprint $t) => $t->dropColumn(['social_settings', 'bio_views']));
        Schema::table('social_post_targets', fn (Blueprint $t) => $t->dropColumn('caption'));
    }
};
