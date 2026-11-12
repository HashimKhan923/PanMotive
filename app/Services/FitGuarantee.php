<?php

namespace App\Services;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ReturnRequest;
use Illuminate\Support\Collection;

/**
 * The Panmotive Fit Guarantee, in one place.
 *
 * The promise: when the seller listed a part for the vehicle in your garage and it doesn't
 * fit, you get a full refund and the seller pays the return shipping. The seller can't just
 * refuse; if they disagree, Panmotive decides. The facts are frozen at checkout (which car,
 * and whether the listing covered it), so a later edit to the listing can't undo the promise.
 */
class FitGuarantee
{
    /** The return reason a buyer picks to use the guarantee. */
    public const REASON = 'Doesn’t fit — the listing said it fits my vehicle';

    public const WRONG_ORDER_REASON = 'I ordered the wrong part';

    public function __construct(private GarageService $garage) {}

    /** Does this listing say it fits the shopper's current vehicle? */
    public function covers(Product $product, ?int $vehicleId = null): bool
    {
        $vehicleId ??= $this->garage->vehicleId();
        if (! $vehicleId) {
            return false;
        }

        return $product->relationLoaded('fitments')
            ? $product->fitments->contains('id', $vehicleId)
            : $product->fitsVehicle($vehicleId);
    }

    /**
     * Fit status for each cart line, for the checkout "fit check".
     *
     * @param  Collection<int, array>  $lines
     * @return array{vehicle: ?string, covered: int, total: int, lines: array<int, bool>}
     */
    public function check(Collection $lines): array
    {
        $vehicleId = $this->garage->vehicleId();
        $result = [];
        foreach ($lines as $i => $line) {
            $result[$i] = $vehicleId ? $this->covers($line['product'], $vehicleId) : false;
        }

        return [
            'vehicle' => $vehicleId ? $this->garage->label() : null,
            'covered' => count(array_filter($result)),
            'total' => count($result),
            'lines' => $result,
        ];
    }

    /** The fit facts to store on an order line at checkout. */
    public function snapshot(Product $product): array
    {
        $vehicleId = $this->garage->vehicleId();

        return [
            'fit_vehicle_id' => $vehicleId,
            'fit_vehicle_label' => $vehicleId ? $this->garage->label() : null,
            'fit_confirmed' => $vehicleId ? $this->covers($product, $vehicleId) : false,
        ];
    }

    /** Can this line be returned under the guarantee? */
    public function applies(OrderItem $item): bool
    {
        return (bool) $item->fit_confirmed;
    }

    /**
     * How often a seller's "fits" promise was wrong over the last 90 days: guarantee returns
     * divided by lines sold with a confirmed fit. Null when there is too little to judge.
     *
     * @return array{rate: ?float, returns: int, sold: int}
     */
    public function sellerRecord(int $sellerId, int $days = 90): array
    {
        $since = now()->subDays($days);
        $sold = OrderItem::where('seller_id', $sellerId)->where('fit_confirmed', true)
            ->where('created_at', '>=', $since)->whereNotIn('status', ['placed', 'cancelled'])->count();
        $returns = ReturnRequest::where('fit_guarantee', true)->where('created_at', '>=', $since)
            ->whereHas('orderItem', fn ($q) => $q->where('seller_id', $sellerId))->count();

        return ['rate' => $sold >= 5 ? round($returns / $sold * 100, 1) : null, 'returns' => $returns, 'sold' => $sold];
    }
}
