<?php

namespace App\Services\Seller;

use App\Models\AdCampaign;
use App\Models\AdCreative;
use App\Models\AdTransaction;
use App\Models\Boost;
use App\Models\Product;
use App\Notifications\LowAdCredit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdServingService
{
    /** Balance below which the seller gets a "running low" heads-up. */
    public const LOW_CREDIT = 5.0;

    /**
     * Sponsored products for a placement, blending legacy quick-boosts with
     * targeted ad-campaign creatives that still have budget today.
     *
     * @param  array{category_id?:int, vehicle_make_id?:int, q?:string}  $context
     * @return Collection<int, array{product: Product, source: string, ref_id: int, headline: ?string, text: ?string}>
     */
    public function forPlacement(string $placement, array $context = [], int $limit = 4): Collection
    {
        $out = collect();

        // 1. Quick boosts (flat daily fee, already paid).
        Boost::live()->where('placement', $placement)
            ->with('product.brand')
            ->get()
            ->filter(fn ($b) => $b->product && $b->product->status === 'active')
            ->each(fn ($b) => $out->push([
                'product' => $b->product, 'source' => 'boost', 'ref_id' => $b->id,
                'headline' => null, 'text' => null,
            ]));

        // 2. Targeted campaign creatives.
        $creatives = AdCreative::where('is_active', true)
            ->whereHas('campaign', fn ($q) => $q->servable()->whereJsonContains('targeting->placements', $placement))
            ->with(['campaign.seller.sellerProfile', 'product.brand'])
            ->get()
            ->filter(function (AdCreative $c) use ($context, $placement) {
                $camp = $c->campaign;
                if (! $c->product || $c->product->status !== 'active' || ! $camp->hasBudgetLeft()) {
                    return false;
                }
                // Don't show an ad the seller can't pay for if it's clicked.
                if ((float) ($camp->seller?->sellerProfile?->ad_credit ?? 0) < (AdCampaign::CPC[$placement] ?? 0.20)) {
                    return false;
                }
                $cats = $camp->targets('categories');
                $makes = $camp->targets('vehicle_make_ids');
                $kw = $camp->targets('keywords');

                $matchCat = ! $cats || in_array($context['category_id'] ?? null, $cats, false);
                $matchMake = ! $makes || (isset($context['vehicle_make_id']) && in_array($context['vehicle_make_id'], $makes, false));
                $matchKw = ! $kw || (isset($context['q']) && collect($kw)->contains(fn ($k) => stripos($context['q'], $k) !== false));

                // If no targeting set at all → broad match. Otherwise any dimension can qualify.
                return (! $cats && ! $makes && ! $kw) || $matchCat || $matchMake || $matchKw;
            })
            ->each(fn (AdCreative $c) => $out->push([
                'product' => $c->product, 'source' => 'creative', 'ref_id' => $c->id,
                'headline' => $c->headline, 'text' => $c->primary_text,
            ]));

        $listed = Product::listed()->whereIn('id', $out->map(fn ($r) => $r['product']->id))->pluck('id')->flip();

        return $out->filter(fn ($r) => $listed->has($r['product']->id))
            ->unique(fn ($r) => $r['product']->id)->shuffle()->take($limit)->values();
    }

    /** @param Collection<int, array> $served */
    public function recordImpressions(Collection $served): void
    {
        $boostIds = $served->where('source', 'boost')->pluck('ref_id');
        $creativeIds = $served->where('source', 'creative')->pluck('ref_id');

        if ($boostIds->isNotEmpty()) {
            Boost::whereIn('id', $boostIds)->increment('impressions');
        }
        if ($creativeIds->isNotEmpty()) {
            AdCreative::whereIn('id', $creativeIds)->increment('impressions');
        }
    }

    /** Record a click and, for campaign creatives, debit CPC from ad credit. */
    public function recordClick(string $source, int $refId): void
    {
        if ($source === 'boost') {
            Boost::whereKey($refId)->increment('clicks');
            \App\Models\MarketingEvent::click(Boost::whereKey($refId)->value('seller_id'), 'ads', $refId);

            return;
        }

        $creative = AdCreative::with('campaign.seller.sellerProfile')->find($refId);
        if (! $creative) {
            return;
        }
        $creative->increment('clicks');
        \App\Models\MarketingEvent::click($creative->campaign?->seller_id, 'ads', $refId);

        $campaign = $creative->campaign;
        $cpc = AdCampaign::CPC[$this->placementFor($campaign)] ?? 0.20;

        $notify = null;

        DB::transaction(function () use ($campaign, $cpc, &$notify) {
            $profile = $campaign->seller->sellerProfile()->lockForUpdate()->first();
            if (! $profile || (float) $profile->ad_credit < $cpc) {
                // Wallet can't cover the click: pause every running campaign of this seller,
                // flagged so a top-up switches them straight back on.
                $paused = AdCampaign::where('seller_id', $campaign->seller_id)->where('status', 'active')
                    ->update(['status' => 'paused', 'paused_for_credit' => true]);
                if ($paused > 0) {
                    $notify = new LowAdCredit((float) ($profile->ad_credit ?? 0), true);
                }

                return;
            }

            $today = now()->toDateString();
            $spentToday = $campaign->spend_day?->toDateString() === $today ? (float) $campaign->spent_today : 0.0;

            $balance = round((float) $profile->ad_credit - $cpc, 2);
            $profile->update(['ad_credit' => $balance]);
            $campaign->update([
                'total_spend' => round((float) $campaign->total_spend + $cpc, 2),
                'spent_today' => round($spentToday + $cpc, 2),
                'spend_day' => $today,
            ]);

            AdTransaction::create([
                'seller_id' => $campaign->seller_id,
                'type' => 'boost_spend',
                'amount' => -$cpc,
                'balance_after' => $balance,
                'description' => "Ad click — {$campaign->name}",
                'source_type' => AdCampaign::class,
                'source_id' => $campaign->id,
            ]);

            // Heads-up the moment the wallet dips under the warning line (once per dip).
            $before = $balance + $cpc;
            if ($balance < self::LOW_CREDIT && $before >= self::LOW_CREDIT) {
                $notify = new LowAdCredit($balance, false);
            }
            // (Daily budget exhaustion needs no action: serving skips it until tomorrow.)
        });

        if ($notify) {
            $campaign->seller->notify($notify);
        }
    }

    /** Resume campaigns that were only paused because the ad wallet ran out. */
    public function resumeAfterTopUp(int $sellerId): int
    {
        $minCpc = min(AdCampaign::CPC);
        $credit = (float) \App\Models\SellerProfile::where('user_id', $sellerId)->value('ad_credit');
        if ($credit < $minCpc) {
            return 0;
        }

        return AdCampaign::where('seller_id', $sellerId)->where('paused_for_credit', true)
            ->update(['status' => 'active', 'paused_for_credit' => false]);
    }

    /** Attribute an order back to a recently-clicked ad. */
    /** The seller who owns a boost or ad creative. */
    public function sellerFor(string $source, int $refId): ?int
    {
        return $source === 'boost'
            ? Boost::whereKey($refId)->value('seller_id')
            : AdCreative::with('campaign:id,seller_id')->find($refId)?->campaign?->seller_id;
    }

    /** $value must be the advertised seller's share of the order, not the whole basket. */
    public function attributeConversion(int $refId, string $source, float $value): void
    {
        if ($source === 'boost') {
            Boost::whereKey($refId)->update([
                'attributed_orders' => DB::raw('attributed_orders + 1'),
                'attributed_revenue' => DB::raw('attributed_revenue + '.(float) $value),
            ]);

            return;
        }

        AdCreative::whereKey($refId)->update([
            'attributed_orders' => DB::raw('attributed_orders + 1'),
            'attributed_revenue' => DB::raw('attributed_revenue + '.(float) $value),
        ]);
    }

    private function placementFor(AdCampaign $campaign): string
    {
        $p = $campaign->targets('placements');

        return in_array('home', $p, true) ? 'home' : (in_array('category', $p, true) ? 'category' : 'search');
    }

    public function endExpired(): int
    {
        return AdCampaign::where('status', 'active')->whereNotNull('ends_at')
            ->where('ends_at', '<=', now())->update(['status' => 'ended']);
    }
}
