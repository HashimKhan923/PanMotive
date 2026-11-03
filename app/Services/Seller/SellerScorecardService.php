<?php

namespace App\Services\Seller;

use App\Enums\OrderStatus;
use App\Models\BuyerClaim;
use App\Models\Conversation;
use App\Models\OrderItem;
use App\Models\ReturnRequest;
use App\Models\User;

class SellerScorecardService
{
    /** Stores with this many orders in 90 days are measured on 90 days; everyone else on 12 months. */
    public const BUSY_STORE_ORDERS = 100;

    /** Return reasons that mean the seller got it wrong (vs. the buyer changing their mind). */
    public const FAULT_RETURN_REASONS = ['Wrong part received', 'Arrived damaged or defective'];

    private const SHIPPED = [
        OrderStatus::Shipped->value, OrderStatus::OutForDelivery->value,
        OrderStatus::Delivered->value, OrderStatus::PayoutReleased->value,
    ];

    /**
     * @return array{
     *   fulfilled_orders:int, on_time_ship_rate:float, defect_rate:float,
     *   response_rate:float, avg_response_hours:?float, cancellation_rate:float,
     *   rating:float, rating_count:int,
     *   tier:string, tier_label:string, next_tier:?string, next_tier_label:?string,
     *   next_gaps:array<int, array{metric:string, have:string, need:string}>
     * }
     */
    public function metrics(User $seller): array
    {
        $profile = $seller->sellerProfile;

        // Rolling windows, like eBay/Amazon: one bad month two years ago shouldn't follow a seller
        // forever. Busy stores are judged on 90 days; smaller ones on 12 months so a couple of
        // orders can't swing the rates wildly.
        $yearItems = OrderItem::where('seller_id', $seller->id)
            ->where('created_at', '>=', now()->subDays(365))
            ->where('status', '!=', OrderStatus::Placed->value) // never paid — not a real order
            ->with('order:id,placed_at,created_at', 'product:id,handling_days,seller_id,shipping_policy_id')
            ->get(['id', 'order_id', 'product_id', 'status', 'created_at', 'shipped_at', 'cancel_reason']);

        $recentOrders = $yearItems->where('created_at', '>=', now()->subDays(90))->pluck('order_id')->unique()->count();
        $windowDays = $recentOrders >= self::BUSY_STORE_ORDERS ? 90 : 365;
        $items = $windowDays === 90 ? $yearItems->where('created_at', '>=', now()->subDays(90)) : $yearItems;
        $since = now()->subDays($windowDays);

        $shipped = $items->filter(fn ($i) => in_array($i->status->value, self::SHIPPED, true));
        // Tier order counts always use the trailing 12 months.
        $fulfilledOrders = $yearItems->filter(fn ($i) => in_array($i->status->value, self::SHIPPED, true))
            ->pluck('order_id')->unique()->count();

        // On time = shipped by the listing's promised handling deadline (business days from payment),
        // the same ship-by date the Orders page shows.
        $withShipDate = $shipped->whereNotNull('shipped_at');
        $onTime = $withShipDate->filter(fn ($i) => $i->shipped_at->lte($i->handlingDeadline()));
        $onTimeRate = $withShipDate->count() > 0
            ? round($onTime->count() / $withShipDate->count() * 100, 1)
            : 100.0;

        // Buyer-requested / fraud / unpaid cancellations aren't the seller's fault — they don't count
        // for or against the seller.
        $excused = \App\Services\Orders\OrderCancellationService::EXCUSED_REASONS;
        $judged = $items->reject(fn ($i) => $i->status === OrderStatus::Cancelled && in_array($i->cancel_reason, $excused, true));
        $cancelled = $judged->filter(fn ($i) => $i->status === OrderStatus::Cancelled)->count();

        // Order defects (Amazon's ODR idea): seller-fault cancellations, returns for a wrong or
        // damaged part, and buyer claims the store didn't resolve itself.
        $faultReturns = ReturnRequest::whereHas('orderItem', fn ($q) => $q->where('seller_id', $seller->id))
            ->where('created_at', '>=', $since)
            ->whereIn('reason', self::FAULT_RETURN_REASONS)
            ->whereNotIn('status', ['rejected'])->count();
        $claims = BuyerClaim::where('seller_id', $seller->id)
            ->where('created_at', '>=', $since)
            ->countingAgainstSeller()->count();
        $orderCount = max($judged->pluck('order_id')->unique()->count(), 1);
        $defectRate = round(($faultReturns + $claims + $cancelled) / $orderCount * 100, 1);
        $cancellationRate = round($cancelled / max($judged->count(), 1) * 100, 1);

        [$responseRate, $avgResponseHours] = $this->responseStats($seller, $windowDays);

        $current = $profile?->tier ?? 'standard';

        $stats = [
            'window_days' => $windowDays,
            'fulfilled_orders' => $fulfilledOrders,
            'on_time_ship_rate' => $onTimeRate,
            'defect_rate' => $defectRate,
            'response_rate' => $responseRate,
            'avg_response_hours' => $avgResponseHours,
            'cancellation_rate' => $cancellationRate,
            'rating' => (float) ($profile?->rating ?? 0),
            'rating_count' => (int) ($profile?->rating_count ?? 0),
        ];

        $earned = $this->evaluateTier($stats);
        $next = $this->nextTier($earned);

        return [
            ...$stats,
            'tier' => $earned,
            'tier_label' => config("marketplace.seller_tiers.$earned.label"),
            'next_tier' => $next,
            'next_tier_label' => $next ? config("marketplace.seller_tiers.$next.label") : null,
            'next_gaps' => $next ? $this->gaps($stats, $next) : [],
            'stored_tier' => $current,
        ];
    }

    /**
     * Compact red/amber/green health signals for the dashboard.
     *
     * @return array{overall:string, signals:array<int, array{label:string, level:string, value:string}>}
     */
    public function healthLights(User $seller): array
    {
        $m = $this->metrics($seller);

        $signals = [
            $this->light('On-time shipping', $m['on_time_ship_rate'].'%', $m['on_time_ship_rate'], [95, 90], false),
            $this->light('Defect rate', $m['defect_rate'].'%', $m['defect_rate'], [3, 6], true),
            $this->light('Response rate', $m['response_rate'].'%', $m['response_rate'], [85, 70], false),
            $this->light('Cancellations', $m['cancellation_rate'].'%', $m['cancellation_rate'], [2, 5], true),
        ];

        $levels = array_column($signals, 'level');
        $overall = in_array('red', $levels, true) ? 'red' : (in_array('amber', $levels, true) ? 'amber' : 'green');

        return ['overall' => $overall, 'signals' => $signals];
    }

    /** @param array{0:float,1:float} $bounds [greenAt, amberAt] */
    private function light(string $label, string $value, float $n, array $bounds, bool $lowerIsBetter): array
    {
        [$good, $ok] = $bounds;
        $level = $lowerIsBetter
            ? ($n <= $good ? 'green' : ($n <= $ok ? 'amber' : 'red'))
            : ($n >= $good ? 'green' : ($n >= $ok ? 'amber' : 'red'));

        return compact('label', 'value', 'level');
    }

    /** Recompute and persist the seller's tier. Returns the new tier. */
    public function refreshTier(User $seller): string
    {
        $m = $this->metrics($seller);

        if ($seller->sellerProfile?->tier_locked) {
            return $seller->sellerProfile->tier; // an admin set this tier by hand
        }

        $seller->sellerProfile?->forceFill([
            'tier' => $m['tier'],
            'tier_evaluated_at' => now(),
        ])->save();

        return $m['tier'];
    }

    private function evaluateTier(array $s): string
    {
        $tiers = config('marketplace.seller_tiers');

        foreach (array_reverse(array_keys($tiers)) as $key) {
            $t = $tiers[$key];
            if ($s['fulfilled_orders'] >= $t['min_orders']
                && $s['on_time_ship_rate'] >= $t['on_time_ship']
                && $s['defect_rate'] <= $t['max_defect_rate']
                && $s['response_rate'] >= $t['response_rate']) {
                return $key;
            }
        }

        return 'standard';
    }

    private function nextTier(string $current): ?string
    {
        $keys = array_keys(config('marketplace.seller_tiers'));
        $i = array_search($current, $keys, true);

        return isset($keys[$i + 1]) ? $keys[$i + 1] : null;
    }

    private function gaps(array $s, string $tier): array
    {
        $t = config("marketplace.seller_tiers.$tier");
        $gaps = [];

        if ($s['fulfilled_orders'] < $t['min_orders']) {
            $gaps[] = ['metric' => 'Fulfilled orders', 'have' => (string) $s['fulfilled_orders'], 'need' => (string) $t['min_orders']];
        }
        if ($s['on_time_ship_rate'] < $t['on_time_ship']) {
            $gaps[] = ['metric' => 'On-time shipping', 'have' => $s['on_time_ship_rate'].'%', 'need' => $t['on_time_ship'].'%'];
        }
        if ($s['defect_rate'] > $t['max_defect_rate']) {
            $gaps[] = ['metric' => 'Order defect rate', 'have' => $s['defect_rate'].'%', 'need' => '≤ '.$t['max_defect_rate'].'%'];
        }
        if ($s['response_rate'] < $t['response_rate']) {
            $gaps[] = ['metric' => 'Message response rate', 'have' => $s['response_rate'].'%', 'need' => $t['response_rate'].'%'];
        }

        return $gaps;
    }

    /**
     * Share of buyer conversations answered within the window, and the average first-reply time.
     * A thread only counts against the seller once it has waited longer than the reply target.
     *
     * @return array{0: float, 1: ?float} [responseRate%, avgResponseHours]
     */
    private function responseStats(User $seller, int $days = 90): array
    {
        $target = (int) config('marketplace.messages.response_target_hours', 24);
        $conversations = Conversation::where('seller_id', $seller->id)
            ->where('created_at', '>=', now()->subDays($days))
            ->with('messages:id,conversation_id,sender_id,created_at')
            ->get();

        $judged = 0;
        $replied = 0;
        $hours = [];

        foreach ($conversations as $c) {
            $firstBuyer = $c->messages->firstWhere('sender_id', $c->buyer_id);
            if (! $firstBuyer) {
                continue;
            }
            $firstReply = $c->messages->first(fn ($m) => $m->sender_id === $c->seller_id && $m->created_at->gte($firstBuyer->created_at));

            if ($firstReply) {
                $judged++;
                $replied++;
                $hours[] = $firstBuyer->created_at->diffInMinutes($firstReply->created_at) / 60;
            } elseif ($firstBuyer->created_at->lt(now()->subHours($target)) && $c->awaiting_reply_since !== null) {
                $judged++; // waited past the target with no reply (and not marked handled)
            }
        }

        $rate = $judged > 0 ? round($replied / $judged * 100, 1) : 100.0;
        $avg = $hours ? round(array_sum($hours) / count($hours), 1) : null;

        return [$rate, $avg];
    }
}
