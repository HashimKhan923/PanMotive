<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The full parts catalog (database/seeders/data/categories.php): 30 departments and about
 * 350 sub-categories. Safe to run again: categories are matched by slug, so existing ones are
 * renamed/moved into place and keep their products; nothing is ever deleted.
 */
class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $tree = require database_path('seeders/data/categories.php');

        foreach (array_values($tree) as $order => [$name, $slug, $children]) {
            $parent = $this->upsert($name, $slug, null, $slug === 'other-parts' ? 999 : $order);

            $childOrder = 0;
            foreach ($children as $key => $value) {
                [$childName, $childSlug] = is_int($key) ? [$value, Str::slug($value)] : [$key, $value];
                $this->upsert($childName, $childSlug, $parent->id, $childOrder++);
            }
        }
    }

    /** @return array<string,string> slug => name of every category in the catalog file */
    public static function slugs(): array
    {
        $out = [];
        foreach (require database_path('seeders/data/categories.php') as [$name, $slug, $children]) {
            $out[$slug] = $name;
            foreach ($children as $key => $value) {
                is_int($key) ? $out[Str::slug($value)] = $value : $out[$value] = $key;
            }
        }

        return $out;
    }

    private function upsert(string $name, string $slug, ?int $parentId, int $order): Category
    {
        $category = Category::firstOrNew(['slug' => $slug]);
        $category->fill(['name' => $name, 'parent_id' => $parentId, 'sort_order' => $order]);
        if (! $category->exists) {
            $category->is_active = true;
        }
        $category->save();

        return $category;
    }
}
