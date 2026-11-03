<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Seller\AdServingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

class AdClickController extends Controller
{
    public function go(Request $request, string $source, int $ref, AdServingService $ads)
    {
        abort_unless(in_array($source, ['boost', 'creative'], true), 404);

        $ads->recordClick($source, $ref);
        Cookie::queue('pm_ad', "{$source}:{$ref}", 60 * 24 * 3); // 3-day view-through window

        $product = Product::find($request->integer('p'));

        return redirect()->to($product ? route('products.show', $product->slug) : route('products.index'));
    }
}
