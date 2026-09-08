<?php

namespace Database\Seeders;

use App\Models\VehicleMake;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class VehicleMakeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Toyota', 'Honda', 'Ford', 'Chevrolet', 'BMW', 'Nissan', 'Jeep', 'RAM'] as $make) {
            VehicleMake::firstOrCreate(
                ['slug' => Str::slug($make)],
                ['name' => $make]
            );
        }
    }
}
