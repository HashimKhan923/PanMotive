<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Panmotive shopping app: "Sign in with Apple" accounts, and the phones that get push notifications.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('apple_id', 128)->nullable()->unique()->after('google_id');
        });

        Schema::create('app_push_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 255)->unique(); // ExponentPushToken[...]
            $table->string('platform', 10)->nullable(); // ios | android
            $table->string('device_id', 80)->nullable()->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_push_tokens');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['apple_id']);
            $table->dropColumn('apple_id');
        });
    }
};
