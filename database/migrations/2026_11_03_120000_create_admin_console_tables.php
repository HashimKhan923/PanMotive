<?php

use Database\Seeders\AdminPermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('body')->nullable();
            $table->string('meta_description')->nullable();
            $table->boolean('is_published')->default(false);
            $table->boolean('show_in_footer')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->boolean('payouts_held')->default(false)->after('commission_rate_override');
            $table->string('payouts_held_reason')->nullable()->after('payouts_held');
            $table->boolean('tier_locked')->default(false)->after('tier');
            $table->text('admin_notes')->nullable()->after('payouts_held_reason');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('banned_reason')->nullable()->after('is_active');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('moderation_note')->nullable()->after('status');
        });

        // Deploys don't run seeders, so the new console permissions are applied here.
        (new RoleSeeder)->run();
        (new AdminPermissionSeeder)->run();
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('moderation_note'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('banned_reason'));
        Schema::table('seller_profiles', fn (Blueprint $t) => $t->dropColumn(['payouts_held', 'payouts_held_reason', 'tier_locked', 'admin_notes']));
        Schema::dropIfExists('pages');
        Schema::dropIfExists('platform_settings');
    }
};
