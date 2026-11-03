<?php

namespace App\Services\Seller;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

class PriceIntelligenceService
{
    /**
     * Compare a listing's price against other sellers' active listings of the
     * same part (matched on part_number or oem_number).
     *
     * @return array{count:int, min:float, median:float, max:float, your_price:float, position:string, delta_pct:float}|null
     */
    public function forProduct(Product $product): ?array
    {
        $pn = trim((string) $product->part_number);
        $oem = trim((string) $product->oem_number);

        if ($pn === '' && $oem === '') {
            return null;
        }

        $prices = Product::query()
            ->where('status', 'active')
            ->where('seller_id', '!=', $product->seller_id)
            ->when($product->id, fn ($q) => $q->where('id', '!=', $product->id))
            ->where(function ($q) use ($pn, $oem) {
                if ($pn !== '') {
                    $q->where('part_number', $pn);
                }
                if ($oem !== '') {
                    $q->orWhere('oem_number', $oem);
                }
            })
            ->select(DB::raw('COALESCE(sale_price, price) as p'))
            ->pluck('p')
            ->map(fn ($v) => (float) $v)
            ->sort()
            ->values();

        if ($prices->isEmpty()) {
            return null;
        }

        $yours = $product->effectivePrice();
        $min = (float) $prices->first();
        $max = (float) $prices->last();
        $mid = $prices->count() % 2
            ? (float) $prices[intdiv($prices->count(), 2)]
            : ((float) $prices[$prices->count() / 2 - 1] + (float) $prices[$prices->count() / 2]) / 2;

        $position = match (true) {
            $yours <= $min => 'lowest',
            $yours <= $mid => 'competitive',
            default => 'above',
        };

        return [
            'count' => $prices->count(),
            'min' => round($min, 2),
            'median' => round($mid, 2),
            'max' => round($max, 2),
            'your_price' => round($yours, 2),
            'position' => $position,
            'delta_pct' => $min > 0 ? round(($yours - $min) / $min * 100, 1) : 0.0,
        ];
    }
}
