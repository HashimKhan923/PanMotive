<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A seller's store record now exists from the moment they start seller sign-up (a pending application
 * admin can see), and setup_step tracks how far the setup wizard got:
 * 0 = just signed up, 1 = business details, 2 = shipping, 3 = payouts connected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->unsignedTinyInteger('setup_step')->default(0);
        });

        // Existing stores: how far they got.
        DB::table('seller_profiles')->update(['setup_step' => 1]);
        DB::table('seller_profiles')->whereNotNull('shipping_settings')->update(['setup_step' => 2]);
        DB::table('seller_profiles')->where('stripe_onboarding_complete', true)->update(['setup_step' => 3]);

        // People who started seller sign-up but never saved step 1: give them their pending application.
        $roleId = DB::table('roles')->where('name', 'seller')->value('id');
        if (! $roleId) {
            return;
        }
        $teamUserIds = Schema::hasTable('seller_team_members')
            ? DB::table('seller_team_members')->whereNotNull('user_id')->pluck('user_id')->all()
            : [];
        $users = DB::table('users')
            ->join('model_has_roles', fn ($j) => $j->on('model_has_roles.model_id', '=', 'users.id')
                ->where('model_has_roles.role_id', $roleId)->where('model_has_roles.model_type', 'App\\Models\\User'))
            ->whereNotIn('users.id', DB::table('seller_profiles')->select('user_id'))
            ->whereNotIn('users.id', $teamUserIds ?: [0])
            ->select('users.id', 'users.name', 'users.created_at')
            ->get();

        foreach ($users as $u) {
            $base = Str::slug($u->name ?: 'store') ?: 'store';
            $slug = $base;
            for ($i = 2; DB::table('seller_profiles')->where('slug', $slug)->exists(); $i++) {
                $slug = "{$base}-{$i}";
            }
            DB::table('seller_profiles')->insert([
                'user_id' => $u->id, 'business_name' => $u->name ?: 'New store', 'slug' => $slug,
                'business_type' => 'sole_prop', 'status' => 'pending', 'kyc_status' => 'unsubmitted',
                'setup_step' => 0, 'created_at' => $u->created_at, 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('seller_profiles', fn (Blueprint $t) => $t->dropColumn('setup_step'));
    }
};
