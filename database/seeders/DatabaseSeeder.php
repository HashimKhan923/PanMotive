<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * What every environment needs. Safe to run again: existing rows are kept.
     * Sample sellers, buyers, products and orders are separate, in DemoSeeder.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            AdminPermissionSeeder::class,
            VehicleMakeSeeder::class,
            CategorySeeder::class,
            AdminSeeder::class,
        ]);
    }
}
