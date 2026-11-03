<?php

namespace Database\Seeders;

use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Three approved sample stores.
 *
 *   php artisan db:seed --class=SellerSeeder --force
 */
class SellerSeeder extends Seeder
{
    public const EMAILS = ['seller1@panmotive.test', 'seller2@panmotive.test', 'seller3@panmotive.test'];

    private const STORES = ['AutoPro Parts Warehouse', 'Junkyard Genuine', 'OEM Direct Supply'];

    public function run(): void
    {
        $this->call([RoleSeeder::class, AdminPermissionSeeder::class]);

        $firstRun = User::whereIn('email', self::EMAILS)->doesntExist();
        // A public server must never have logins with a well-known password.
        $password = app()->environment('production') ? Str::password(20, symbols: false) : 'password';

        foreach (self::EMAILS as $i => $email) {
            $business = self::STORES[$i];
            $user = User::firstOrCreate(
                ['email' => $email],
                ['name' => $business, 'password' => Hash::make($password), 'is_active' => true]
            );
            $user->assignRole('seller');
            SellerProfile::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'business_name' => $business, 'business_type' => 'llc',
                    'status' => 'approved', 'kyc_status' => 'verified',
                    'stripe_account_id' => 'acct_demo_'.Str::random(8),
                    'stripe_onboarding_complete' => true,
                    'rating' => round(mt_rand(40, 50) / 10, 1),
                    'rating_count' => mt_rand(12, 240),
                ]
            );
        }

        if ($firstRun) {
            $this->command?->line("Seller logins seller1-3@panmotive.test, password: {$password}");
        }
    }
}
