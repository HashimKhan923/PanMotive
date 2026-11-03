<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        // Keeps the menu order set by the taxonomy migration: Other Parts always sits last.
        $tree = [
            ['Brakes', 0, ['Brake Pads', 'Brake Rotors', 'Calipers', 'Brake Lines']],
            ['Engine', 1, ['Belts', 'Filters', 'Spark Plugs', 'Gaskets']],
            ['Suspension', 2, ['Shocks & Struts', 'Control Arms', 'Ball Joints']],
            ['Electrical', 3, ['Batteries', 'Alternators', 'Starters', 'Sensors']],
            ['Cooling', 4, ['Radiators', 'Water Pumps', 'Thermostats']],
            ['Lighting', 5, ['Headlights', 'Tail Lights', 'LED & Accent Lights']],
            ['Body & Exterior', 6, ['Mirrors', 'Spoilers & Wings', 'Bumpers & Grilles']],
            ['Other Parts', 999, []],
        ];

        foreach ($tree as [$parentName, $order, $children]) {
            $parent = Category::firstOrCreate(
                ['slug' => Str::slug($parentName)],
                ['name' => $parentName, 'sort_order' => $order]
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
