<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;

class DealsController extends Controller
{
    public function index()
    {
        $deals = Product::onDeal()->listed()
            ->with('brand', 'seller.sellerProfile')
            ->orderByRaw('CASE WHEN deal_ends_at IS NULL THEN 1 ELSE 0 END, deal_ends_at ASC')
            ->orderByRaw('(price - sale_price) / price DESC')
            ->paginate(24);

        $dealOfDay = Product::onDeal()->listed()
            ->orderByRaw('(price - sale_price) / price DESC')->first();

        return view('storefront.deals.index', compact('deals', 'dealOfDay'));
    }
}
