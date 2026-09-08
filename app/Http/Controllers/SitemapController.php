<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;

class SitemapController extends Controller
{
    public function index()
    {
        $xml = Cache::remember('sitemap.xml', now()->addHours(6), function () {
            $products = Product::where('status', 'active')->select('slug', 'updated_at')->get();
            $categories = Category::select('slug', 'updated_at')->get();

            return view('sitemap', compact('products', 'categories'))->render();
        });

        return response($xml, 200)->header('Content-Type', 'text/xml');
    }
}
