<?php

namespace App\Livewire\Seller;

use App\Models\OffsiteAdAttribution;
use App\Models\OffsiteAdClick;
use App\Models\OrderItem;
use App\Services\Marketing\OffsiteAdsService;
use App\Support\SellerContext;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/** Seller view of Offsite Ads: what it is, opt in/out, and what it's earned them. */
#[Layout('layouts.seller')]
class OffsiteAds extends Component
{
    use WithPagination;

    public function toggle(OffsiteAdsService $offsite): void
    {
        abort_unless(SellerContext::can('marketing'), 403);
        $sellerId = auth()->id();
        $profile = auth()->user()->sellerProfile;

        if (! $profile->offsite_ads_opt_out && ! $offsite->canOptOut($sellerId)) {
            $this->dispatch('toast', message: 'Offsite Ads is required for stores with over $'.number_format(config('marketplace.offsite_ads.large_seller_threshold')).' in yearly sales.', kind: 'error');

            return;
        }

        $profile->update(['offsite_ads_opt_out' => ! $profile->offsite_ads_opt_out]);
        // Feeds rebuild with/without this store's listings.
        foreach (array_keys(config('marketplace.offsite_ads.channels', [])) as $c) {
            Cache::forget("offsite_feed.$c");
        }

        $this->dispatch('toast', message: $profile->offsite_ads_opt_out
            ? 'Offsite Ads turned off — your listings leave our ads within a few hours.'
            : 'Offsite Ads turned on — your listings will start appearing in our ads.');
    }

    public function render(OffsiteAdsService $offsite)
    {
        $sellerId = auth()->id();
        $profile = auth()->user()->sellerProfile;
        $since = now()->subDays(30);

        $attributions = OffsiteAdAttribution::where('seller_id', $sellerId);

        // Fees on lines later cancelled/refunded are never collected (those lines pay nothing out).
        $feesCharged = (float) OrderItem::where('seller_id', $sellerId)
            ->whereNotIn('status', ['cancelled', 'refunded'])->sum('offsite_ad_fee');

        return view('livewire.seller.offsite-ads', [
            'participating' => $offsite->participates($profile),
            'optedOut' => (bool) $profile->offsite_ads_opt_out,
            'canOptOut' => $offsite->canOptOut($sellerId),
            'rate' => $offsite->feeRate($sellerId),
            'enabled' => $offsite->enabled(),
            'channels' => config('marketplace.offsite_ads.channels'),
            'clicks30' => OffsiteAdClick::where('seller_id', $sellerId)->where('created_at', '>=', $since)->count(),
            'clicksByChannel' => OffsiteAdClick::where('seller_id', $sellerId)->where('created_at', '>=', $since)
                ->selectRaw('channel, count(*) as c')->groupBy('channel')->pluck('c', 'channel'),
            'orders' => (clone $attributions)->count(),
            'sales' => (float) (clone $attributions)->sum('attributed_sales'),
            'fees' => $feesCharged,
            'history' => (clone $attributions)->with('order:id,order_number')->latest()->paginate(15),
        ]);
    }
}
