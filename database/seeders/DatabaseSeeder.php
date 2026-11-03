<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Everything, including sample sellers, customers, products and orders from DemoSeeder.
     * Safe to run again: existing rows are kept.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            AdminPermissionSeeder::class,
            VehicleMakeSeeder::class,
            CategorySeeder::class,
            AdminSeeder::class,
            DemoSeeder::class,
        ]);
    }
}
