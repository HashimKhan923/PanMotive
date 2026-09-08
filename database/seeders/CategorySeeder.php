<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $tree = [
            'Brakes' => ['Brake Pads', 'Brake Rotors', 'Calipers'],
            'Engine' => ['Belts', 'Filters', 'Spark Plugs'],
            'Suspension' => ['Shocks & Struts', 'Control Arms'],
            'Electrical' => ['Batteries', 'Alternators', 'Starters'],
        ];

        foreach ($tree as $parentName => $children) {
            $parent = Category::firstOrCreate(
                ['slug' => Str::slug($parentName)],
                ['name' => $parentName]
            );

            foreach ($children as $childName) {
                Category::firstOrCreate(
                    ['slug' => Str::slug($childName)],
                    ['name' => $childName, 'parent_id' => $parent->id]
                );
            }
        }
    }
}
