<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Services\Search\CatalogSearch;
use Illuminate\Http\Request;

class SellerStoreController extends Controller
{
    public function show(Request $request, SellerProfile $sellerProfile)
    {
        abort_unless($sellerProfile->status === 'approved', 404);

        $sellerId = $sellerProfile->user_id;

        $products = Product::where('seller_id', $sellerId)
            ->where('status', 'active')
            ->with('brand')
            ->when($request->filled('q'), fn ($q) => app(CatalogSearch::class)->apply($q, (string) $request->q))
            ->when($request->sort === 'price_asc', fn ($q) => $q->orderByLivePrice('asc'))
            ->when($request->sort === 'price_desc', fn ($q) => $q->orderByLivePrice('desc'))
            ->when($request->sort === 'rating', fn ($q) => $q->orderByDesc('avg_rating'))
            ->when(! $request->filled('sort'), fn ($q) => $q->latest())
            ->paginate(24)
            ->withQueryString();

        $stats = [
            'listings' => Product::where('seller_id', $sellerId)->where('status', 'active')->count(),
            'since' => $sellerProfile->created_at,
        ];

        $featured = collect($sellerProfile->featured_product_ids ?? [])->isNotEmpty()
            ? Product::whereIn('id', $sellerProfile->featured_product_ids)->where('seller_id', $sellerId)
                ->where('status', 'active')->with('brand')->get()
            : collect();

        $offers = \App\Models\Coupon::where('seller_id', $sellerId)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            // Never advertise one-person codes (accepted offers, customer thank-yous) or used-up ones.
            ->whereNull('user_id')
            ->whereRaw('code NOT LIKE ?', ['OFFER-%'])
            ->where(fn ($q) => $q->whereNull('usage_limit')->orWhereColumn('times_used', '<', 'usage_limit'))
            ->latest()->take(3)->get();

        $affiliateProgram = \App\Models\AffiliateProgram::where('seller_id', $sellerId)
            ->where('is_active', true)
            ->first();

        $loyaltyAccount = auth()->check() && $sellerProfile->loyaltyEnabled()
            ? \App\Models\StoreLoyaltyAccount::where('user_id', auth()->id())->where('seller_id', $sellerId)->first()
            : null;

        return view('storefront.seller-store', compact(
            'sellerProfile', 'products', 'stats', 'featured', 'offers', 'affiliateProgram', 'loyaltyAccount'
        ));
    }
}
