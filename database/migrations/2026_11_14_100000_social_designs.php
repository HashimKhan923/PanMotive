<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Social designer: sellers' saved free-hand designs (the editable canvas plus a rendered PNG),
 * and an image per channel on a post, so Instagram can get a square and Pinterest a tall pin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_designs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('size', 20);
            $table->longText('canvas');
            $table->string('image_path')->nullable();
            $table->timestamps();
            $table->index(['seller_id', 'updated_at']);
        });

        Schema::table('social_post_targets', function (Blueprint $table) {
            $table->string('media_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('social_post_targets', fn (Blueprint $t) => $t->dropColumn('media_path'));
        Schema::dropIfExists('social_designs');
    }
};
