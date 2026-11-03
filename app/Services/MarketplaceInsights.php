<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\BuyerClaim;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ReturnRequest;
use App\Services\Orders\OrderCancellationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** How healthy the marketplace is, worked out from real orders. Shared by the dashboard and Insights. */
class MarketplaceInsights
{
    private const UNPAID = ['placed', 'cancelled'];

    /**
     * Each row has a value, a plain rating and what it means. The good/watch limits are a guide based on
     * common marketplace standards, chosen to match what sellers are held to in their scorecards.
     *
     * @return list<array{label: string, value: ?float, suffix: string, rating: string, hint: string}>
     */
    public function health(Carbon $from): array
    {
        $lines = OrderItem::where('created_at', '>=', $from)->where('status', '!=', OrderStatus::Placed->value)->with('seller.sellerProfile', 'product')->get();
        $total = $lines->count();

        $shipped = $lines->filter(fn ($i) => $i->shipped_at && $i->handlingDeadline());
        $onTime = $shipped->isEmpty() ? null : round($shipped->filter(fn ($i) => $i->shipped_at->lte($i->handlingDeadline()))->count() / $shipped->count() * 100, 1);

        $cancelled = $lines->filter(fn ($i) => $i->status === OrderStatus::Cancelled
            && ! in_array($i->cancel_reason, OrderCancellationService::EXCUSED_REASONS, true))->count();
        $refunded = $lines->where('status', OrderStatus::Refunded)->count();
        $returns = ReturnRequest::where('created_at', '>=', $from)->count();
        $claims = BuyerClaim::where('created_at', '>=', $from)->count();

        $rate = fn (int $n) => $total ? round($n / $total * 100, 1) : null;

        return [
            $this->row('On-time shipping', $onTime, 95, 85, true, 'Lines shipped by the promised date.'),
            $this->row('Refund rate', $rate($refunded), 3, 8, false, 'Lines refunded after being paid.'),
            $this->row('Return rate', $rate($returns), 5, 10, false, 'Return requests per order line.'),
            $this->row('Claim rate', $rate($claims), 1, 3, false, 'Buyers saying something went wrong.'),
            $this->row('Seller cancellations', $rate($cancelled), 2, 5, false, 'Cancelled by sellers, not buyers or failed payments.'),
        ];
    }

    private function row(string $label, ?float $value, float $good, float $watch, bool $higherIsBetter, string $hint): array
    {
        $rating = match (true) {
            $value === null => 'none',
            $higherIsBetter ? $value >= $good : $value <= $good => 'good',
            $higherIsBetter ? $value >= $watch : $value <= $watch => 'watch',
            default => 'poor',
        };

        return ['label' => $label, 'value' => $value, 'suffix' => '%', 'rating' => $rating, 'hint' => $hint];
    }

    /** @return list<array{label: string, value: int}> visitors down to delivered orders */
    public function funnel(Carbon $from): array
    {
        $orders = Order::where('created_at', '>=', $from);

        return [
            ['label' => 'Shoppers who viewed a part', 'value' => DB::table('product_views')->where('viewed_at', '>=', $from)->distinct()->count('viewer_key')],
            ['label' => 'Orders placed', 'value' => (clone $orders)->count()],
            ['label' => 'Orders paid', 'value' => (clone $orders)->whereNotIn('status', self::UNPAID)->count()],
            ['label' => 'Orders with a delivery', 'value' => OrderItem::where('created_at', '>=', $from)->whereNotNull('delivered_at')->distinct()->count('order_id')],
        ];
    }

    /** @return array{buyers: int, repeat: int, rate: float, guest_orders: int} */
    public function loyalty(Carbon $from): array
    {
        $buyers = Order::where('created_at', '>=', $from)->whereNotIn('status', self::UNPAID)->whereNotNull('buyer_id')->distinct()->pluck('buyer_id');
        $repeat = Order::whereIn('buyer_id', $buyers)->whereNotIn('status', self::UNPAID)
            ->selectRaw('buyer_id, COUNT(*) AS c')->groupBy('buyer_id')->get()->where('c', '>', 1)->count();

        return [
            'buyers' => $buyers->count(),
            'repeat' => $repeat,
            'rate' => $buyers->count() ? round($repeat / $buyers->count() * 100, 1) : 0.0,
            'guest_orders' => Order::where('created_at', '>=', $from)->whereNotIn('status', self::UNPAID)->whereNull('buyer_id')->count(),
        ];
    }

    /** @return array{states: array, weekdays: array} where and when paid orders happen */
    public function patterns(Carbon $from): array
    {
        $orders = Order::where('created_at', '>=', $from)->whereNotIn('status', self::UNPAID)->get(['id', 'total', 'credits_used', 'shipping_address', 'created_at']);
        $paid = fn ($o) => (float) $o->total + (float) $o->credits_used;

        $states = $orders->groupBy(fn ($o) => strtoupper((string) (($o->shipping_address['state'] ?? '') ?: 'Unknown')))
            ->map(fn ($group, $state) => ['label' => $state, 'value' => round($group->sum($paid), 2)])
            ->sortByDesc('value')->take(8)->values()->all();

        $byDay = $orders->groupBy(fn ($o) => $o->created_at->dayOfWeekIso);
        $names = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
        $weekdays = collect($names)->map(fn ($name, $day) => ['label' => $name, 'value' => $byDay->has($day) ? round($byDay[$day]->sum($paid), 2) : 0])->values()->all();

        return compact('states', 'weekdays');
    }

    public function topProducts(Carbon $from): array
    {
        return OrderItem::where('created_at', '>=', $from)->whereNotIn('status', ['placed', 'cancelled', 'refunded'])
            ->selectRaw('product_id, product_title, SUM(line_total) AS revenue, SUM(quantity) AS units')
            ->groupBy('product_id', 'product_title')->orderByDesc('revenue')->limit(6)->get()
            ->map(fn ($r) => ['label' => $r->product_title.' ('.(int) $r->units.' sold)', 'value' => (float) $r->revenue, 'href' => $r->product_id && auth()->user()?->can('catalog.manage') ? route('admin.products.show', $r->product_id) : null])->all();
    }

    /** How much of the sales depend on the biggest store: a big risk if that one store leaves. */
    public function concentration(Carbon $from): array
    {
        $bySeller = OrderItem::where('created_at', '>=', $from)->whereNotIn('status', ['placed', 'cancelled', 'refunded'])
            ->selectRaw('seller_id, SUM(line_total) AS revenue')->groupBy('seller_id')->orderByDesc('revenue')->pluck('revenue');
        $total = (float) $bySeller->sum();

        return [
            'sellers' => $bySeller->count(),
            'top_share' => $total > 0 ? round((float) $bySeller->first() / $total * 100, 1) : 0.0,
            'top3_share' => $total > 0 ? round((float) $bySeller->take(3)->sum() / $total * 100, 1) : 0.0,
        ];
    }

    /** Live listings with something wrong, each with its count and the filter that lists them. */
    public function catalogIssues(): array
    {
        $live = fn () => Product::listed();

        return [
            ['label' => 'Out of stock', 'count' => $live()->where('quantity', '<=', 0)->count(), 'filter' => 'out_of_stock', 'hint' => 'Shoppers can see these but can\'t buy them.'],
            ['label' => 'Running low', 'count' => $live()->where('quantity', '>', 0)->whereNotNull('reorder_point')->whereColumn('quantity', '<=', 'reorder_point')->count(), 'filter' => 'low_stock', 'hint' => 'At or below the seller\'s reorder point.'],
            ['label' => 'No vehicle fitment', 'count' => $live()->whereDoesntHave('fitments')->count(), 'filter' => 'no_fitment', 'hint' => 'Won\'t appear when shoppers filter by their car.'],
            ['label' => 'No photo', 'count' => $live()->whereDoesntHave('media')->count(), 'filter' => 'no_photo', 'hint' => 'Listings without photos rarely sell.'],
            ['label' => 'No brand', 'count' => $live()->whereNull('brand_id')->count(), 'filter' => 'no_brand', 'hint' => 'Missing from brand filters.'],
            ['label' => 'Filed in Other Parts', 'count' => $live()->whereHas('category', fn ($c) => $c->where('slug', 'other-parts'))->count(), 'filter' => 'uncategorised', 'hint' => 'The importer couldn\'t match a category.'],
        ];
    }
}
