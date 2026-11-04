<?php

namespace App\Services\Shipping;

use App\Models\ShippingPolicy;
use App\Models\User;

/**
 * Shipping estimate — weight-tiered flat rates per seller shipment, overridable
 * by a per-seller ShippingPolicy (flat rate, free-over threshold, regional
 * surcharges) chosen at the product level. PanMotive Plus members and a
 * loyalty-tier threshold still short-circuit to free.
 */
class ShippingEstimateService
{
    /**
     * @param  iterable  $lines  each: ['product' => Product, 'quantity' => int, 'line_total' => float]
     * @return array{shipping: float, free: bool, threshold: float, per_seller: array<int, float>}
     */
    public function estimate(iterable $lines, ?User $buyer = null, ?string $shipToState = null): array
    {
        $lines = collect($lines);

        // PanMotive Plus — free shipping on everything, no minimum.
        if ($buyer && $buyer->isPlusMember()) {
            $perSellerFree = [];
            $grand = 0.0;
            foreach ($lines as $line) {
                $grand += $line['line_total'];
                $perSellerFree[$line['product']->seller_id] = 0.0;
            }

            return ['shipping' => 0.0, 'free' => $grand > 0, 'threshold' => 0.0, 'per_seller' => $perSellerFree];
        }

        $loyaltyThreshold = $buyer
            ? app(\App\Services\Marketing\LoyaltyService::class)->freeShippingThreshold($buyer)
            : (float) config('marketplace.loyalty_tiers.bronze.free_shipping_threshold', 75);

        $bySeller = [];
        foreach ($lines as $line) {
            $sid = $line['product']->seller_id;
            $bySeller[$sid]['items'][] = $line;
            $bySeller[$sid]['weight'] = ($bySeller[$sid]['weight'] ?? 0)
                + ((float) ($line['product']->weight_lbs ?: 2) * $line['quantity']);
            $bySeller[$sid]['subtotal'] = ($bySeller[$sid]['subtotal'] ?? 0) + $line['line_total'];
            $bySeller[$sid]['units'] = ($bySeller[$sid]['units'] ?? 0) + (int) $line['quantity'];
        }

        $perSeller = [];
        $total = 0.0;
        foreach ($bySeller as $sid => $agg) {
            $policy = $this->policyFor($sid, $agg['items']);

            $rate = match ($policy?->method()) {
                'free' => 0.0,
                // Flat rate for the first item, plus the per-item extra for every additional one.
                'flat' => (float) $policy->flat_rate + max(0, $agg['units'] - 1) * (float) ($policy->additional_item_rate ?? 0),
                default => $this->rateForWeight($agg['weight']),
            };

            if ($policy && $policy->method() !== 'free') {
                $rate += $policy->surchargeFor($shipToState);
            }

            $threshold = $policy?->free_over !== null && $policy?->free_over > 0
                ? (float) $policy->free_over
                : $loyaltyThreshold;

            if ($threshold > 0 && $agg['subtotal'] >= $threshold) {
                $rate = 0.0;
            }

            $perSeller[$sid] = round($rate, 2);
            $total += $rate;
        }

        $grandSubtotal = array_sum(array_column($bySeller, 'subtotal'));

        return [
            'shipping' => round($total, 2),
            'free' => $total <= 0 && $grandSubtotal > 0,
            'threshold' => $loyaltyThreshold,
            'per_seller' => $perSeller,
        ];
    }

    private const STATE_NAMES = [
        'alabama' => 'AL', 'alaska' => 'AK', 'arizona' => 'AZ', 'arkansas' => 'AR', 'california' => 'CA', 'colorado' => 'CO',
        'connecticut' => 'CT', 'delaware' => 'DE', 'district of columbia' => 'DC', 'washington dc' => 'DC', 'washington d.c.' => 'DC',
        'florida' => 'FL', 'georgia' => 'GA', 'hawaii' => 'HI', 'idaho' => 'ID', 'illinois' => 'IL', 'indiana' => 'IN', 'iowa' => 'IA',
        'kansas' => 'KS', 'kentucky' => 'KY', 'louisiana' => 'LA', 'maine' => 'ME', 'maryland' => 'MD', 'massachusetts' => 'MA',
        'michigan' => 'MI', 'minnesota' => 'MN', 'mississippi' => 'MS', 'missouri' => 'MO', 'montana' => 'MT', 'nebraska' => 'NE',
        'nevada' => 'NV', 'new hampshire' => 'NH', 'new jersey' => 'NJ', 'new mexico' => 'NM', 'new york' => 'NY',
        'north carolina' => 'NC', 'north dakota' => 'ND', 'ohio' => 'OH', 'oklahoma' => 'OK', 'oregon' => 'OR',
        'pennsylvania' => 'PA', 'rhode island' => 'RI', 'south carolina' => 'SC', 'south dakota' => 'SD', 'tennessee' => 'TN',
        'texas' => 'TX', 'utah' => 'UT', 'vermont' => 'VT', 'virginia' => 'VA', 'washington' => 'WA', 'west virginia' => 'WV',
        'wisconsin' => 'WI', 'wyoming' => 'WY', 'puerto rico' => 'PR', 'guam' => 'GU', 'us virgin islands' => 'VI',
        'virgin islands' => 'VI', 'american samoa' => 'AS', 'northern mariana islands' => 'MP',
    ];

    /** "California", "calif", " ca " → "CA", so restrictions and surcharges can't be dodged by spelling. */
    public static function normalizeState(?string $state): ?string
    {
        $s = strtolower(trim((string) $state));
        if ($s === '') {
            return null;
        }
        if (preg_match('/^[a-z]{2}$/', $s)) {
            return strtoupper($s);
        }
        $s = preg_replace('/\s+/', ' ', str_replace('.', '', $s)); // "Washington D.C." → "washington dc"

        return self::STATE_NAMES[$s] ?? strtoupper(trim((string) $state));
    }

    /**
     * Cart lines the seller won't ship to this state (e.g. CARB-restricted parts to CA).
     *
     * @return list<string> product titles that can't be delivered there
     */
    public function restrictedTitles(iterable $lines, ?string $state): array
    {
        $state = self::normalizeState($state);
        if (blank($state)) {
            return [];
        }

        return collect($lines)
            ->filter(fn ($l) => $this->policyForProduct($l['product'])?->excludes($state))
            ->map(fn ($l) => $l['product']->title)
            ->unique()->values()->all();
    }

    /** A listing's own policy, else its seller's default. */
    public function policyForProduct(\App\Models\Product $product): ?ShippingPolicy
    {
        if ($product->shipping_policy_id) {
            return $product->relationLoaded('shippingPolicy')
                ? $product->shippingPolicy
                : ShippingPolicy::find($product->shipping_policy_id);
        }

        return ShippingPolicy::where('seller_id', $product->seller_id)->where('is_default', true)->first();
    }

    /** The policy that governs a seller's shipment: the highest-value item's policy, else the seller default. */
    private function policyFor(int $sellerId, array $items): ?ShippingPolicy
    {
        $withPolicy = collect($items)
            ->filter(fn ($l) => $l['product']->shipping_policy_id)
            ->sortByDesc('line_total')
            ->first();

        if ($withPolicy) {
            return $withPolicy['product']->relationLoaded('shippingPolicy')
                ? $withPolicy['product']->shippingPolicy
                : ShippingPolicy::find($withPolicy['product']->shipping_policy_id);
        }

        return ShippingPolicy::where('seller_id', $sellerId)->where('is_default', true)->first();
    }

    private function rateForWeight(float $lbs): float
    {
        return match (true) {
            $lbs <= 1 => 4.99,
            $lbs <= 5 => 7.99,
            $lbs <= 20 => 12.99,
            $lbs <= 50 => 24.99,
            default => 49.99,
        };
    }
}
