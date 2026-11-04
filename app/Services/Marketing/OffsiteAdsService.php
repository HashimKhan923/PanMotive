<?php

namespace App\Services\Marketing;

use App\Enums\OrderStatus;
use App\Models\OffsiteAdAttribution;
use App\Models\OffsiteAdClick;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SellerProfile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Offsite Ads, Etsy-style: PanMotive runs ads for sellers' listings on its own
 * ad accounts; a seller pays a fee only on an order placed within the
 * attribution window after a buyer clicked one of their ads. The fee comes out
 * of the seller's payout, so every payout path charges it automatically.
 */
class OffsiteAdsService
{
    public const COOKIE = 'pm_oa';

    public function enabled(): bool
    {
        return (bool) config('marketplace.offsite_ads.enabled');
    }

    /** Trailing-12-month sales — decides the lower rate and the mandatory-participation rule. */
    public function annualSales(int $sellerId): float
    {
        return (float) Cache::remember("offsite_ads.annual_sales.$sellerId", 3600, fn () => OrderItem::where('seller_id', $sellerId)
            ->where('created_at', '>=', now()->subYear())
            ->whereNotIn('status', [OrderStatus::Placed->value, OrderStatus::Cancelled->value, OrderStatus::Refunded->value])
            ->sum('line_total'));
    }

    public function isLargeSeller(int $sellerId): bool
    {
        return $this->annualSales($sellerId) >= (float) config('marketplace.offsite_ads.large_seller_threshold');
    }

    public function feeRate(int $sellerId): float
    {
        return $this->isLargeSeller($sellerId)
            ? (float) config('marketplace.offsite_ads.large_seller_fee_pct')
            : (float) config('marketplace.offsite_ads.fee_pct');
    }

    public function canOptOut(int $sellerId): bool
    {
        return ! $this->isLargeSeller($sellerId);
    }

    /** Is this seller's catalog advertised (and charged) right now? */
    public function participates(?SellerProfile $profile): bool
    {
        if (! $this->enabled() || ! $profile || $profile->status !== 'approved') {
            return false;
        }

        return ! $profile->offsite_ads_opt_out || $this->isLargeSeller($profile->user_id);
    }

    /**
     * A buyer landed on a listing from one of our ads. Logs the click and returns
     * the updated cookie value, or null when the click shouldn't count.
     */
    public function recordClick(Product $product, string $code, string $visitorKey, ?string $cookie): ?string
    {
        $channels = config('marketplace.offsite_ads.channels', []);
        if (! array_key_exists($code, $channels) || $product->status !== 'active'
            || ! $this->participates($product->seller?->sellerProfile)) {
            return null;
        }

        // One click per visitor per listing per day — refreshes and back-button don't inflate stats.
        $click = OffsiteAdClick::where('product_id', $product->id)->where('visitor_key', $visitorKey)
            ->where('created_at', '>=', now()->subDay())->latest()->first()
            ?? OffsiteAdClick::create([
                'product_id' => $product->id,
                'seller_id' => $product->seller_id,
                'channel' => $code,
                'visitor_key' => $visitorKey,
            ]);
        if ($click->wasRecentlyCreated) {
            \App\Models\MarketingEvent::click($product->seller_id, 'offsite_ads', $click->id);
        }

        $entries = collect($this->decode($cookie))
            ->reject(fn ($e) => (int) $e['s'] === $product->seller_id)
            ->push(['s' => $product->seller_id, 'c' => $click->id, 'ch' => $code, 't' => now()->timestamp])
            ->sortByDesc('t')->take(20)->values()->all();

        return json_encode($entries);
    }

    /**
     * Charge the Offsite Ads fee on every seller in this order whose ad the buyer
     * clicked within the window. Idempotent — an order/seller pair is charged once.
     */
    public function attribute(Order $order, ?string $cookie): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $clicks = collect($this->decode($cookie))->keyBy('s');
        if ($clicks->isEmpty()) {
            return 0;
        }

        $cap = (float) config('marketplace.offsite_ads.fee_cap');
        $charged = 0;

        $lines = $order->items()->whereNotIn('status', [OrderStatus::Cancelled->value, OrderStatus::Refunded->value])->get();

        foreach ($lines->groupBy('seller_id') as $sellerId => $sellerLines) {
            $click = $clicks->get((int) $sellerId);
            if (! $click) {
                continue;
            }
            $profile = SellerProfile::where('user_id', $sellerId)->first();
            if (! $this->participates($profile)
                || OffsiteAdAttribution::where('order_id', $order->id)->where('seller_id', $sellerId)->exists()) {
                continue;
            }

            $sales = round((float) $sellerLines->sum('line_total'), 2);
            $rate = $this->feeRate((int) $sellerId);
            $fee = min($cap, round($sales * $rate / 100, 2));
            if ($fee <= 0) {
                continue;
            }

            DB::transaction(function () use ($order, $sellerId, $click, $sales, $rate, $fee, $sellerLines) {
                OffsiteAdAttribution::create([
                    'order_id' => $order->id,
                    'seller_id' => $sellerId,
                    'offsite_ad_click_id' => OffsiteAdClick::whereKey($click['c'])->exists() ? $click['c'] : null,
                    'channel' => $click['ch'],
                    'clicked_at' => \Illuminate\Support\Carbon::createFromTimestamp($click['t']),
                    'attributed_sales' => $sales,
                    'fee_rate' => $rate,
                    'fee_amount' => $fee,
                ]);
                \App\Models\MarketingEvent::conversion((int) $sellerId, 'offsite_ads', $click['c'], $order->id, $sales);

                // Spread the fee over this seller's lines in proportion to their value;
                // the last line takes the rounding remainder so the shares sum exactly.
                $remaining = $fee;
                $count = $sellerLines->count();
                foreach ($sellerLines->values() as $i => $line) {
                    $share = $i === $count - 1
                        ? $remaining
                        : round($fee * (float) $line->line_total / max($sales, 0.01), 2);
                    $remaining = round($remaining - $share, 2);

                    $line->update([
                        'offsite_ad_fee' => $share,
                        'seller_payout_amount' => round((float) $line->seller_payout_amount - $share, 2),
                    ]);
                }
            });

            $charged++;
        }

        return $charged;
    }

    /** @return list<array{s:int,c:int,ch:string,t:int}> live (in-window) cookie entries */
    private function decode(?string $cookie): array
    {
        $data = json_decode((string) $cookie, true);
        if (! is_array($data)) {
            return [];
        }
        $cutoff = now()->subDays((int) config('marketplace.offsite_ads.attribution_days'))->timestamp;

        return array_values(array_filter($data, fn ($e) => is_array($e)
            && isset($e['s'], $e['c'], $e['ch'], $e['t'])
            && (int) $e['t'] >= $cutoff));
    }
}
