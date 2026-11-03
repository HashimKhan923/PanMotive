<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class AdminSeeder extends Seeder
{
    /**
     * First super admin, with a random password printed once. An existing account keeps its password.
     *
     *   SEED_ADMIN_EMAIL=you@example.com php artisan db:seed --class=AdminSeeder --force
     */
    public function run(): void
    {
        Artisan::call('admin:create', [
            'email' => env('SEED_ADMIN_EMAIL', 'admin@panmotive.test'),
        ], $this->command?->getOutput());
    }
}
