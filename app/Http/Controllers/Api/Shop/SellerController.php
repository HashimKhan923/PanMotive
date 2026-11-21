<?php

namespace App\Http\Controllers\Api\Shop;

use App\Http\Controllers\Controller;
use App\Http\Resources\Shop\ShopPresenter;
use App\Models\AffiliateProgram;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\StoreLoyaltyAccount;
use App\Models\User;
use App\Services\Search\CatalogSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A seller's public store in the app: header, offers, featured parts, listings, follow. */
class SellerController extends Controller
{
    public function show(Request $request, SellerProfile $sellerProfile): JsonResponse
    {
        abort_unless($sellerProfile->status === 'approved', 404);
        $sellerId = $sellerProfile->user_id;
        $present = new ShopPresenter;

        $featured = collect($sellerProfile->featured_product_ids ?? [])->isNotEmpty()
            ? Product::listed()->whereIn('id', $sellerProfile->featured_product_ids)->where('seller_id', $sellerId)
                ->with('seller.sellerProfile', 'media')->withCount('activeVariants')->get()
            : collect();

        $offers = Coupon::where('seller_id', $sellerId)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereNull('user_id') // never one-person codes
            ->whereRaw('code NOT LIKE ?', ['OFFER-%'])
            ->where(fn ($q) => $q->whereNull('usage_limit')->orWhereColumn('times_used', '<', 'usage_limit'))
            ->latest()->take(3)->get();

        $loyalty = $sellerProfile->loyaltyEnabled() ? ($sellerProfile->loyalty ?? []) : null;
        $account = $loyalty && $request->user()
            ? StoreLoyaltyAccount::where('user_id', $request->user()->id)->where('seller_id', $sellerId)->first()
            : null;
        $affiliate = AffiliateProgram::where('seller_id', $sellerId)->where('is_active', true)->first();

        return response()->json(['store' => $present->seller($sellerProfile, full: true) + [
            'id' => $sellerId,
            'listings' => Product::listed()->where('seller_id', $sellerId)->count(),
            'followers' => User::whereHas('followedSellers', fn ($q) => $q->where('seller_id', $sellerId))->count(),
            'following' => $request->user() ? $request->user()->followedSellers()->where('seller_id', $sellerId)->exists() : false,
            'offers' => $offers->map(fn ($c) => [
                'code' => $c->code, 'type' => $c->type, 'value' => (float) $c->value,
                'min_order' => $c->min_order_total ? (float) $c->min_order_total : null,
                'expires_at' => $c->expires_at?->toIso8601String(),
            ])->values(),
            'featured' => $present->cards($featured),
            'loyalty' => $loyalty ? [
                'points_per_dollar' => $sellerProfile->pointsPerDollar(),
                'redeem_cents_per_point' => (int) ($loyalty['redeem_cents_per_point'] ?? 1),
                'min_redeem' => (int) ($loyalty['min_redeem'] ?? 0),
                'points' => $account ? (int) $account->points : null,
            ] : null,
            'affiliate' => $affiliate ? ['commission_pct' => (float) $affiliate->commission_pct, 'cookie_days' => (int) $affiliate->cookie_days] : null,
            'share_url' => route('sellers.show', $sellerProfile),
        ]]);
    }

    public function products(Request $request, SellerProfile $sellerProfile): JsonResponse
    {
        abort_unless($sellerProfile->status === 'approved', 404);
        $request->validate(['q' => 'nullable|string|max:120', 'sort' => 'nullable|in:,newest,price_asc,price_desc,rating', 'page' => 'nullable|integer|min:1']);

        $products = Product::listed()->where('seller_id', $sellerProfile->user_id)
            ->with('seller.sellerProfile', 'media')->withCount('activeVariants')
            ->when($request->filled('q'), fn ($q) => app(CatalogSearch::class)->apply($q, (string) $request->q))
            ->when($request->sort === 'price_asc', fn ($q) => $q->orderByLivePrice('asc'))
            ->when($request->sort === 'price_desc', fn ($q) => $q->orderByLivePrice('desc'))
            ->when($request->sort === 'rating', fn ($q) => $q->orderByDesc('avg_rating'))
            ->when(! in_array($request->sort, ['price_asc', 'price_desc', 'rating'], true), fn ($q) => $q->latest())
            ->paginate(24, ['*'], 'page', (int) $request->input('page', 1));

        return response()->json([
            'products' => (new ShopPresenter)->cards($products->getCollection()),
            'meta' => ShopPresenter::page($products),
        ]);
    }

    public function follow(Request $request, SellerProfile $sellerProfile): JsonResponse
    {
        abort_unless($sellerProfile->status === 'approved', 404);
        $user = $request->user();
        abort_if($user->id === $sellerProfile->user_id, 422, 'You can\'t follow your own store.');

        $following = $user->followedSellers()->where('seller_id', $sellerProfile->user_id)->exists();
        $following
            ? $user->followedSellers()->detach($sellerProfile->user_id)
            : $user->followedSellers()->syncWithoutDetaching([$sellerProfile->user_id]);

        return response()->json([
            'following' => ! $following,
            'followers' => User::whereHas('followedSellers', fn ($q) => $q->where('seller_id', $sellerProfile->user_id))->count(),
        ]);
    }
}
