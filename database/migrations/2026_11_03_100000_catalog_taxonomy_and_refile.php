<?php

use App\Services\Seller\CategoryResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The category tree had nowhere for lighting or body parts, and the CSV importer dropped anything
     * it couldn't match into the first root category — Brakes. So LED reflectors and mirrors were
     * listed as brakes. Add the missing branches, plus "Other Parts" as an honest fallback, and
     * re-file only those listings.
     */
    public function up(): void
    {
        $tree = [
            ['Lighting', 'lighting', [['Headlights', 'headlights'], ['Tail Lights', 'tail-lights'], ['LED & Accent Lights', 'led-accent-lights']]],
            ['Body & Exterior', 'body-exterior', [['Mirrors', 'mirrors'], ['Spoilers & Wings', 'spoilers-wings'], ['Bumpers & Grilles', 'bumpers-grilles']]],
            ['Other Parts', 'other-parts', []],
        ];

        foreach ($tree as [$name, $slug, $children]) {
            $parentId = $this->category($name, $slug, null);
            foreach ($children as [$childName, $childSlug]) {
                $this->category($childName, $childSlug, $parentId);
            }
        }

        // Menus keep the core mechanical categories first; Other Parts always sits last.
        DB::table('categories')->where('slug', 'lighting')->update(['sort_order' => 5]);
        DB::table('categories')->where('slug', 'body-exterior')->update(['sort_order' => 6]);
        DB::table('categories')->where('slug', 'other-parts')->update(['sort_order' => 999]);

        $resolver = app(CategoryResolver::class);
        $other = DB::table('categories')->where('slug', 'other-parts')->value('id');
        $brakes = DB::table('categories')->where('slug', 'brakes')->value('id');
        $electrical = DB::table('categories')->where('slug', 'electrical')->value('id');

        // Filed directly under Brakes without mentioning a brake part: importer fallback, not a choice.
        if ($brakes) {
            DB::table('products')->where('category_id', $brakes)
                ->where('title', 'not like', '%brake%')->where('title', 'not like', '%rotor%')
                ->where('title', 'not like', '%caliper%')->where('title', 'not like', '%pad%')
                ->get(['id', 'title'])
                ->each(fn ($p) => DB::table('products')->where('id', $p->id)
                    ->update(['category_id' => $resolver->resolve($p->title) ?? $other]));
        }

        // Headlights used to map to Electrical because no lighting category existed.
        if ($electrical) {
            DB::table('products')->where('category_id', $electrical)
                ->where(fn ($q) => $q->where('title', 'like', '%headlight%')->orWhere('title', 'like', '%head lamp%')->orWhere('title', 'like', '%tail light%'))
                ->get(['id', 'title'])
                ->each(fn ($p) => DB::table('products')->where('id', $p->id)
                    ->update(['category_id' => $resolver->resolve($p->title) ?? $electrical]));
        }
    }

    private function category(string $name, string $slug, ?int $parentId): int
    {
        return DB::table('categories')->where('slug', $slug)->value('id')
            ?? DB::table('categories')->insertGetId([
                'name' => $name, 'slug' => $slug, 'parent_id' => $parentId,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Re-filed listings can't be told apart afterwards; the extra categories are harmless to keep.
    }
};
