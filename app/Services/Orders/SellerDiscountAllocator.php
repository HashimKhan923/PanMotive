<?php

namespace App\Services\Orders;

use App\Models\Coupon;
use Illuminate\Support\Collection;

/**
 * Works out how much of each cart line's price a SELLER is giving away — bundle
 * savings and the seller's own coupon codes — so that discount comes off that
 * seller's order lines (and so their payout and our commission), instead of
 * being silently absorbed by Panmotive at the order level.
 *
 * Platform-wide coupons (no seller) are Panmotive's own promotion and are not
 * allocated here — they stay an order-level discount we fund.
 */
class SellerDiscountAllocator
{
    /**
     * @param  Collection<int, array{product:\App\Models\Product, line_total:float}>  $lines
     * @param  array<int, float>  $bundleByProduct  product id => bundle saving (from CartService::bundleDiscount)
     * @return array<int, float> line index => seller-funded discount for that line
     */
    public function allocate(Collection $lines, array $bundleByProduct, ?Coupon $coupon, float $couponDiscount): array
    {
        $discounts = array_fill_keys($lines->keys()->all(), 0.0);

        // Bundle savings: a product's saving is split across its lines (variants) by value.
        foreach ($bundleByProduct as $productId => $amount) {
            $idx = $lines->filter(fn ($l) => $l['product']->id === (int) $productId)->keys()->all();
            $this->spread($discounts, $lines, $idx, (float) $amount);
        }

        // Seller coupon: spread over the lines it applies to — one listing (offer codes)
        // or all of that seller's lines.
        if ($coupon?->seller_id && $couponDiscount > 0) {
            $idx = $lines->filter(fn ($l) => $coupon->product_id
                ? $l['product']->id === $coupon->product_id
                : $l['product']->seller_id === $coupon->seller_id)->keys()->all();
            $this->spread($discounts, $lines, $idx, $couponDiscount);
        }

        // A line can never be discounted below zero.
        foreach ($discounts as $i => $d) {
            $discounts[$i] = round(min($d, (float) $lines[$i]['line_total']), 2);
        }

        return $discounts;
    }

    /** Split $amount over the given lines in proportion to their value; last line takes the rounding remainder. */
    private function spread(array &$discounts, Collection $lines, array $idx, float $amount): void
    {
        if (! $idx || $amount <= 0) {
            return;
        }

        $base = max(0.01, (float) collect($idx)->sum(fn ($i) => $lines[$i]['line_total']));
        $left = round($amount, 2);

        foreach (array_values($idx) as $n => $i) {
            $share = $n === count($idx) - 1
                ? $left
                : round($amount * (float) $lines[$i]['line_total'] / $base, 2);
            $left = round($left - $share, 2);
            $discounts[$i] = round($discounts[$i] + $share, 2);
        }
    }
}
