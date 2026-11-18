<?php

namespace Database\Seeders;

use App\Models\Brand;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Real US auto-parts brands (database/seeders/data/brands.php). Safe to run again: brands are
 * matched by slug and existing ones (with their logos and products) are left as they are.
 */
class BrandSeeder extends Seeder
{
    public function run(): void
    {
        $existing = Brand::pluck('slug')->flip();
        $now = now();
        $rows = [];

        foreach (require database_path('seeders/data/brands.php') as $name) {
            $slug = Str::slug($name); // same rule the CSV importer uses, so "K&N" is one brand
            if ($slug === '' || isset($existing[$slug])) {
                continue;
            }
            $existing[$slug] = true;
            $rows[] = ['name' => $name, 'slug' => $slug, 'created_at' => $now, 'updated_at' => $now];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('brands')->insert($chunk);
        }
    }
}
