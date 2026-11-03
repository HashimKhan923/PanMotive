<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Six sample customers.
 *
 *   php artisan db:seed --class=CustomerSeeder --force
 */
class CustomerSeeder extends Seeder
{
    public function run(): void
    {
        $firstRun = User::where('email', 'buyer1@panmotive.test')->doesntExist();
        // A public server must never have logins with a well-known password.
        $password = app()->environment('production') ? Str::password(20, symbols: false) : 'password';

        foreach (range(1, 6) as $n) {
            User::firstOrCreate(
                ['email' => "buyer{$n}@panmotive.test"],
                ['name' => "Demo Buyer {$n}", 'password' => Hash::make($password), 'is_active' => true]
            );
        }

        if ($firstRun) {
            $this->command?->line("Customer logins buyer1-6@panmotive.test, password: {$password}");
        }
    }
}
