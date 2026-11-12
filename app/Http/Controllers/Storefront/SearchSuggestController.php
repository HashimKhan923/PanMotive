<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\GarageService;
use App\Services\Search\CatalogSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Suggestions while typing in the search box: matching parts (with photo, price and whether
 * they fit the shopper's vehicle), plus categories and brands. Uses the same search as the
 * shop page (Meilisearch for typos, the database for the final say), so what's suggested is
 * what the results page will show.
 */
class SearchSuggestController extends Controller
{
    public function __invoke(Request $request, CatalogSearch $search, GarageService $garage): JsonResponse
    {
        $q = trim(mb_substr((string) $request->query('q', ''), 0, 80));
        if (mb_strlen($q) < 2) {
            return response()->json(['products' => [], 'categories' => [], 'brands' => []]);
        }

        $vehicleId = $garage->vehicleId();
        $key = 'search.suggest.'.md5(mb_strtolower($q)).'.'.($vehicleId ?? 0);

        $data = Cache::remember($key, 60, function () use ($q, $search, $vehicleId) {
            $products = $search->orderByRelevance($search->apply(Product::listed(), $q), $q)
                ->with(['media', 'fitments:id'])
                ->take(6)->get();

            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';

            return [
                'products' => $products->map(fn (Product $p) => [
                    'title' => $p->title,
                    'url' => route('products.show', $p->slug),
                    'price' => '$'.number_format($p->effectivePrice(), 2),
                    'image' => $p->getFirstMediaUrl('images', 'thumb') ?: null,
                    'part' => $p->part_number ?: null,
                    'fits' => $vehicleId ? $p->fitments->contains('id', $vehicleId) : null,
                    'stock' => (int) $p->quantity > 0,
                ])->values()->all(),
                'categories' => Category::where('is_active', true)->where('name', 'like', $like)
                    ->orderBy('name')->take(3)->get(['name', 'slug'])
                    ->map(fn ($c) => ['name' => $c->name, 'url' => route('products.index', ['category' => $c->slug])])->all(),
                'brands' => Brand::where('name', 'like', $like)->orderBy('name')->take(3)->get(['name', 'slug'])
                    ->map(fn ($b) => ['name' => $b->name, 'url' => route('products.index', ['brand' => $b->slug])])->all(),
            ];
        });

        return response()->json($data + [
            'all' => route('products.index', ['q' => $q]),
            'vehicle' => $vehicleId ? $garage->label() : null,
        ])->header('Cache-Control', 'private, max-age=30');
    }
}
