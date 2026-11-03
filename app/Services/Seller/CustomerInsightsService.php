<?php

namespace App\Services\Seller;

use App\Enums\OrderStatus;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A seller's customer base, derived from real order history: every registered
 * buyer with at least one paid (non-cancelled) line from this store, with
 * lifetime spend, order count and recency — plus the segments built on them.
 */
class CustomerInsightsService
{
    public const SEGMENTS = [
        'new' => 'New (last 30 days)',
        'repeat' => 'Repeat buyers',
        'one_time' => 'One-time buyers',
        'vip' => 'VIP',
        'lapsed' => 'Lapsed (90+ days)',
        'followers' => 'Store followers',
        'blocked' => 'Blocked buyers',
    ];

    /** VIP = big spenders or very frequent buyers. */
    public const VIP_SPEND = 500;

    public const VIP_ORDERS = 5;

    /** Item statuses that are not real, kept sales. */
    public static function excludedStatuses(): array
    {
        return [OrderStatus::Placed->value, OrderStatus::Cancelled->value, OrderStatus::Refunded->value];
    }

    /** Users who've bought from this seller, each carrying their per-store stats as attributes. */
    public function query(int $sellerId): Builder
    {
        $stats = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.seller_id', $sellerId)
            ->whereNotNull('orders.buyer_id')
            ->whereNotIn('order_items.status', self::excludedStatuses())
            ->groupBy('orders.buyer_id')
            ->selectRaw('orders.buyer_id,
                COUNT(DISTINCT order_items.order_id) as orders_count,
                SUM(order_items.quantity) as units,
                SUM(order_items.line_total) as spent,
                MIN(order_items.created_at) as first_order_at,
                MAX(order_items.created_at) as last_order_at');

        return User::query()
            ->joinSub($stats, 'cs', 'cs.buyer_id', '=', 'users.id')
            ->select('users.id', 'users.name', 'users.email', 'users.created_at',
                'cs.orders_count', 'cs.units', 'cs.spent', 'cs.first_order_at', 'cs.last_order_at');
    }

    public function applySegment(Builder $q, string $segment, int $sellerId): Builder
    {
        return match ($segment) {
            'new' => $q->where('cs.first_order_at', '>=', now()->subDays(30)),
            'repeat' => $q->where('cs.orders_count', '>=', 2),
            'one_time' => $q->where('cs.orders_count', 1),
            'vip' => $q->where(fn ($w) => $w->where('cs.spent', '>=', self::VIP_SPEND)->orWhere('cs.orders_count', '>=', self::VIP_ORDERS)),
            'lapsed' => $q->where('cs.last_order_at', '<', now()->subDays(90)),
            'followers' => $q->whereExists(fn ($e) => $e->selectRaw('1')->from('seller_followers')
                ->whereColumn('seller_followers.user_id', 'users.id')
                ->where('seller_followers.seller_id', $sellerId)),
            'blocked' => $q->whereExists(fn ($e) => $e->selectRaw('1')->from('seller_blocked_buyers')
                ->whereColumn('seller_blocked_buyers.buyer_id', 'users.id')
                ->where('seller_blocked_buyers.seller_id', $sellerId)),
            default => $q,
        };
    }

    /** Badges for one customer row (expects the attributes added by query()). */
    public function badges(User $customer): array
    {
        $badges = [];
        if ((float) $customer->spent >= self::VIP_SPEND || (int) $customer->orders_count >= self::VIP_ORDERS) {
            $badges[] = 'VIP';
        }
        if ((int) $customer->orders_count >= 2) {
            $badges[] = 'Repeat';
        }
        if ($customer->first_order_at && now()->diffInDays($customer->first_order_at, true) <= 30) {
            $badges[] = 'New';
        }
        if ($customer->last_order_at && now()->diffInDays($customer->last_order_at, true) > 90) {
            $badges[] = 'Lapsed';
        }

        return $badges;
    }

    /** Headline numbers for the top of the Customers page. */
    public function summary(int $sellerId): array
    {
        $all = $this->query($sellerId)->get();
        $repeat = $all->where('orders_count', '>=', 2)->count();

        return [
            'customers' => $all->count(),
            'repeat_rate' => $all->count() ? round($repeat / $all->count() * 100, 1) : 0.0,
            'avg_ltv' => $all->count() ? round((float) $all->sum('spent') / $all->count(), 2) : 0.0,
            'new_30d' => $all->filter(fn ($c) => \Illuminate\Support\Carbon::parse($c->first_order_at)->gte(now()->subDays(30)))->count(),
            'guest_orders' => OrderItem::where('seller_id', $sellerId)
                ->whereNotIn('status', self::excludedStatuses())
                ->whereHas('order', fn ($o) => $o->whereNull('buyer_id'))
                ->distinct('order_id')->count('order_id'),
        ];
    }

    /** Last known shipping city/state for a buyer, from their latest order with this seller. */
    public function lastLocation(int $sellerId, int $buyerId): ?string
    {
        $item = OrderItem::where('seller_id', $sellerId)
            ->whereHas('order', fn ($o) => $o->where('buyer_id', $buyerId))
            ->with('order:id,shipping_address')
            ->latest()->first();
        $a = $item?->order?->shipping_address ?? [];
        $loc = trim(implode(', ', array_filter([$a['city'] ?? null, $a['state'] ?? null])));

        return $loc !== '' ? $loc : null;
    }
}
