<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AdminTools;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BrandController extends Controller
{
    use AdminTools;

    public function index(Request $request)
    {
        return view('admin.brands.index', [
            'brands' => Brand::withCount('products')
                ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', $this->like($request->q)))
                ->orderBy('name')->paginate(30)->withQueryString(),
            'allBrands' => Brand::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:120|unique:brands,name']);
        $base = Str::slug($data['name']) ?: 'brand';
        $slug = $base;
        for ($i = 2; Brand::where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        $brand = Brand::create(['name' => $data['name'], 'slug' => $slug]);
        $this->audit('Created brand', $brand);

        return back()->with('success', "{$brand->name} added.");
    }

    public function update(Request $request, Brand $brand)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120', Rule::unique('brands', 'name')->ignore($brand->id)]]);

        $this->audit('Renamed brand', $brand, ['from' => $brand->name, 'to' => $data['name']]);
        $brand->update($data);
        Cache::forget('home.brands.v2');

        return back()->with('success', 'Brand renamed.');
    }

    /** Sellers import "ACDelco" and "AC Delco" as two brands. This folds one into the other. */
    public function merge(Request $request, Brand $brand)
    {
        $data = $request->validate(['into' => ['required', Rule::exists('brands', 'id'), Rule::notIn([$brand->id])]]);

        $moved = Product::withTrashed()->where('brand_id', $brand->id)->update(['brand_id' => $data['into']]);
        $this->audit('Merged brand', null, ['from' => $brand->name, 'into' => $data['into'], 'moved_listings' => $moved]);
        $brand->delete();
        Cache::forget('home.brands.v2');

        return back()->with('success', "Merged. {$moved} listings moved.");
    }

    public function destroy(Brand $brand)
    {
        if (Product::withTrashed()->where('brand_id', $brand->id)->exists()) {
            return back()->with('error', "{$brand->name} still has listings. Merge it into another brand instead.");
        }

        $this->audit('Deleted brand', null, ['name' => $brand->name]);
        $brand->delete();
        Cache::forget('home.brands.v2');

        return back()->with('success', 'Brand deleted.');
    }
}
