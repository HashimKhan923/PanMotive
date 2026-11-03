<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AdminTools;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    use AdminTools;

    public function index()
    {
        $counts = Product::where('status', 'active')->selectRaw('category_id, COUNT(*) AS c')->groupBy('category_id')->pluck('c', 'category_id');
        $all = Category::orderBy('sort_order')->orderBy('name')->get();

        $roots = $all->whereNull('parent_id')->values()->each(function ($root) use ($all, $counts) {
            $root->kids = $all->where('parent_id', $root->id)->values()->each(fn ($c) => $c->live = (int) ($counts[$c->id] ?? 0));
            $root->live = (int) ($counts[$root->id] ?? 0) + $root->kids->sum('live');
        });

        return view('admin.categories.index', ['roots' => $roots, 'all' => $all]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['slug'] = $this->uniqueSlug($data['name']);

        $category = Category::create($data);
        $this->refreshMenus();
        $this->audit('Created category', $category, $data);

        return back()->with('success', "{$category->name} added.");
    }

    public function update(Request $request, Category $category)
    {
        $data = $this->validated($request, $category);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['is_active'] = $request->boolean('is_active');

        $before = $category->only(array_keys($data));
        $category->update($data);
        $this->refreshMenus();
        $this->audit('Edited category', $category, ['before' => $before, 'after' => $data]);

        return back()->with('success', 'Category saved.');
    }

    /** Listings can't be left without a category, so they move to the one the admin picks. */
    public function destroy(Request $request, Category $category)
    {
        $data = $request->validate(['move_to' => ['nullable', Rule::exists('categories', 'id'), Rule::notIn([$category->id])]]);

        $listings = Product::withTrashed()->where('category_id', $category->id)->count();
        if ($listings > 0 && empty($data['move_to'])) {
            return back()->with('error', "{$category->name} has {$listings} listings. Choose where to move them first.");
        }
        if (Category::where('parent_id', $category->id)->exists()) {
            return back()->with('error', "{$category->name} still has subcategories. Move or delete them first.");
        }

        if ($listings > 0) {
            Product::withTrashed()->where('category_id', $category->id)->update(['category_id' => $data['move_to']]);
        }
        $this->audit('Deleted category', null, ['name' => $category->name, 'moved_listings' => $listings, 'moved_to' => $data['move_to'] ?? null]);
        $category->delete();
        $this->refreshMenus();

        return back()->with('success', 'Category deleted.');
    }

    private function validated(Request $request, ?Category $category = null): array
    {
        return $request->validate([
            'name' => 'required|string|max:120',
            'parent_id' => ['nullable', Rule::exists('categories', 'id')->whereNull('parent_id'), Rule::notIn(array_filter([$category?->id]))],
            'sort_order' => 'nullable|integer|min:0|max:9999',
            'description' => 'nullable|string|max:2000',
        ]);

    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        for ($i = 2; Category::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    private function refreshMenus(): void
    {
        Cache::forget('shop.category_roots');
    }
}
