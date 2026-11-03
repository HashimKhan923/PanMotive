<?php

namespace App\Services\Seller;

use App\Enums\OrderStatus;
use App\Models\OrderItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A unified money view for a seller, assembled from order_items, returns,
 * ad_transactions and payout_advances. Read-only — no new source of truth.
 */
class SellerLedgerService
{
    private const EARNING_ITEM_STATUSES_EXCLUDED = ['placed', 'cancelled'];

    /**
     * Every money event in the window, newest first.
     *
     * @return Collection<int, array{date:Carbon, type:string, ref:string, description:string, gross:float, fee:float, net:float}>
     */
    public function entries(int $sellerId, Carbon $from, Carbon $to): Collection
    {
        $rows = collect();

        // Sales — one row per order item that counts toward earnings.
        OrderItem::where('seller_id', $sellerId)
            ->whereNotIn('status', self::EARNING_ITEM_STATUSES_EXCLUDED)
            ->whereBetween('created_at', [$from, $to])
            ->with('order:id,order_number')
            ->get()
            ->each(function (OrderItem $i) use ($rows) {
                $rows->push([
                    'date' => $i->created_at,
                    'type' => 'sale',
                    'ref' => $i->order?->order_number ?? "#{$i->order_id}",
                    'description' => "Sale — {$i->product_title}".($i->quantity > 1 ? " ×{$i->quantity}" : ''),
                    'gross' => (float) $i->line_total,
                    'fee' => -1 * (float) $i->platform_fee_amount,
                    // Payout already has any Offsite Ads fee / affiliate commission taken out —
                    // add them back here and show each as its own line below, so every row adds up.
                    'net' => (float) $i->seller_payout_amount + (float) $i->offsite_ad_fee + (float) $i->affiliate_fee + (float) $i->label_fee,
                ]);

                if ((float) $i->offsite_ad_fee > 0) {
                    $rows->push([
                        'date' => $i->created_at,
                        'type' => 'offsite_ad_fee',
                        'ref' => $i->order?->order_number ?? "#{$i->order_id}",
                        'description' => "Offsite Ads fee — {$i->product_title}",
                        'gross' => 0.0,
                        'fee' => -1 * (float) $i->offsite_ad_fee,
                        'net' => -1 * (float) $i->offsite_ad_fee,
                    ]);
                }

                if ((float) $i->label_fee > 0) {
                    $rows->push([
                        'date' => $i->shipped_at ?? $i->created_at,
                        'type' => 'shipping_label',
                        'ref' => $i->order?->order_number ?? "#{$i->order_id}",
                        'description' => "Shipping label — {$i->product_title}",
                        'gross' => 0.0,
                        'fee' => -1 * (float) $i->label_fee,
                        'net' => -1 * (float) $i->label_fee,
                    ]);
                }

                if ((float) $i->affiliate_fee > 0) {
                    $rows->push([
                        'date' => $i->created_at,
                        'type' => 'affiliate_fee',
                        'ref' => $i->order?->order_number ?? "#{$i->order_id}",
                        'description' => "Affiliate commission — {$i->product_title}",
                        'gross' => 0.0,
                        'fee' => -1 * (float) $i->affiliate_fee,
                        'net' => -1 * (float) $i->affiliate_fee,
                    ]);
                }
            });

        // Refunds — every refunded line (a return, a claim the seller settled, a refund without
        // return, an admin decision) reverses that line's economics on the day it was refunded.
        OrderItem::where('seller_id', $sellerId)
            ->where('status', OrderStatus::Refunded->value)
            ->with(['order:id,order_number', 'statusHistory'])
            ->get()
            ->each(function (OrderItem $i) use ($rows, $from, $to) {
                $refundedAt = optional($i->statusHistory->where('status', OrderStatus::Refunded->value)->sortByDesc('id')->first())->created_at
                    ?? $i->updated_at;
                if ($refundedAt->lt($from) || $refundedAt->gt($to)) {
                    return;
                }
                $rma = \App\Models\ReturnRequest::where('order_item_id', $i->id)->where('status', 'refunded')->value('rma_number');
                $rows->push([
                    'date' => $refundedAt,
                    'type' => 'refund',
                    'ref' => $rma ?: ($i->order?->order_number ?? "#{$i->order_id}"),
                    'description' => "Refund — {$i->product_title}",
                    'gross' => -1 * (float) $i->line_total,
                    'fee' => (float) $i->platform_fee_amount,       // fee returned to seller
                    'net' => -1 * (float) $i->seller_payout_amount,
                ]);
            });

        // Advertising spend / credit top-ups.
        DB::table('ad_transactions')
            ->where('seller_id', $sellerId)
            ->whereBetween('created_at', [$from, $to])
            ->get()
            ->each(function ($t) use ($rows) {
                // Buying credit (or being granted it) just fills the ad wallet — it isn't
                // income. Only spending it (clicks, boosts) and boost refunds touch earnings.
                $walletOnly = in_array($t->type, ['topup', 'promo_grant'], true);
                $rows->push([
                    'date' => Carbon::parse($t->created_at),
                    'type' => 'ad_'.$t->type,
                    'ref' => '—',
                    'description' => $walletOnly
                        ? $t->description.' — $'.number_format((float) $t->amount, 2).' added to ad wallet'
                        : $t->description,
                    'gross' => 0.0,
                    'fee' => 0.0,
                    'net' => $walletOnly ? 0.0 : (float) $t->amount,   // signed: − spend, + boost refund
                ]);
            });

        // Instant-payout fees.
        DB::table('payout_advances')
            ->join('order_items', 'order_items.id', '=', 'payout_advances.order_item_id')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('payout_advances.seller_id', $sellerId)
            ->where('payout_advances.fee', '>', 0)
            ->whereBetween('payout_advances.created_at', [$from, $to])
            ->select('payout_advances.created_at', 'payout_advances.fee', 'orders.order_number')
            ->get()
            ->each(function ($a) use ($rows) {
                $rows->push([
                    'date' => Carbon::parse($a->created_at),
                    'type' => 'instant_payout_fee',
                    'ref' => $a->order_number,
                    'description' => 'Instant payout fee',
                    'gross' => 0.0,
                    'fee' => -1 * (float) $a->fee,
                    'net' => -1 * (float) $a->fee,
                ]);
            });

        return $rows->sortByDesc(fn ($r) => $r['date']->timestamp)->values();
    }

    /** @return array<string,float|array> */
    public function pnl(int $sellerId, Carbon $from, Carbon $to): array
    {
        $entries = $this->entries($sellerId, $from, $to);

        $grossSales = (float) $entries->where('type', 'sale')->sum('gross');
        $refunds = (float) abs($entries->where('type', 'refund')->sum('gross'));
        // Net fee = fees charged on sales, less fees handed back on refunds.
        $platformFees = (float) abs($entries->where('type', 'sale')->sum('fee'))
            - (float) $entries->where('type', 'refund')->sum('fee');
        // Net advertising cost: clicks + boosts, less unused boost days refunded to the wallet.
        $adSpend = max(0, -1 * (float) $entries->filter(fn ($r) => in_array($r['type'], ['ad_boost_spend', 'ad_refund'], true))->sum('net'));
        $instantFees = (float) abs($entries->where('type', 'instant_payout_fee')->sum('net'));
        $offsiteFees = (float) abs($entries->where('type', 'offsite_ad_fee')->sum('net'));
        $affiliateFees = (float) abs($entries->where('type', 'affiliate_fee')->sum('net'));
        $labelFees = (float) abs($entries->where('type', 'shipping_label')->sum('net'));

        return [
            'gross_sales' => round($grossSales, 2),
            'refunds' => round($refunds, 2),
            'net_sales' => round($grossSales - $refunds, 2),
            'platform_fees' => round($platformFees, 2),
            'ad_spend' => round($adSpend, 2),
            'instant_payout_fees' => round($instantFees, 2),
            'offsite_ad_fees' => round($offsiteFees, 2),
            'affiliate_fees' => round($affiliateFees, 2),
            'label_fees' => round($labelFees, 2),
            // Each entry's `net` is already the signed effect on seller earnings.
            'net_earnings' => round($entries->sum('net'), 2),
            'orders' => $entries->where('type', 'sale')->count(),
            'payout_status' => $this->payoutStatus($sellerId),
        ];
    }

    /**
     * Where the seller's money is right now.
     *
     * @return array{released:float, in_escrow:float, pending:float, on_hold:float, reversed:float}
     */
    public function payoutStatus(int $sellerId): array
    {
        $escrow = [OrderStatus::Shipped->value, OrderStatus::OutForDelivery->value, OrderStatus::Delivered->value];
        $pending = [OrderStatus::Paid->value, OrderStatus::SellerAccepted->value]; // unpaid checkouts aren't money owed yet
        $hold = [OrderStatus::ReturnRequested->value, OrderStatus::ReturnApproved->value, OrderStatus::Disputed->value];
        $sum = fn (array $statuses) => (float) OrderItem::where('seller_id', $sellerId)->whereIn('status', $statuses)
            ->whereNull('payout_released_at')->sum('seller_payout_amount');

        return [
            'released' => (float) OrderItem::where('seller_id', $sellerId)
                ->where('status', OrderStatus::PayoutReleased->value)->sum('seller_payout_amount'),
            'in_escrow' => $sum($escrow),
            'pending' => $sum($pending),
            // A return or dispute is open — paid once it's resolved in the seller's favour.
            'on_hold' => $sum($hold),
            // Paid out, then refunded — the transfer was taken back.
            'reversed' => (float) OrderItem::where('seller_id', $sellerId)
                ->where('status', OrderStatus::Refunded->value)->whereNotNull('payout_released_at')->sum('seller_payout_amount'),
        ];
    }

    /**
     * Released payouts with their Stripe transfer reference, for reconciliation
     * against the connected-account balance.
     *
     * @return Collection<int, array{order:string, released_at:?Carbon, amount:float, reference:?string}>
     */
    public function releasedPayouts(int $sellerId, int $limit = 200): Collection
    {
        return OrderItem::where('seller_id', $sellerId)
            ->where('status', OrderStatus::PayoutReleased->value)
            ->with(['order:id,order_number', 'statusHistory'])
            ->orderByDesc('payout_released_at')
            ->limit($limit)
            ->get()
            ->map(function (OrderItem $i) {
                $note = optional($i->statusHistory->firstWhere('status', OrderStatus::PayoutReleased->value))->note;
                preg_match('/transfer (\S+)/i', (string) $note, $m);

                return [
                    'order' => $i->order?->order_number ?? "#{$i->order_id}",
                    'released_at' => $i->payout_released_at,
                    'amount' => (float) $i->seller_payout_amount,
                    'reference' => $m[1] ?? null,
                ];
            });
    }

    public function toCsv(int $sellerId, Carbon $from, Carbon $to): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Date', 'Type', 'Reference', 'Description', 'Gross', 'Fee', 'Net']);

        foreach ($this->entries($sellerId, $from, $to) as $e) {
            fputcsv($out, [
                $e['date']->toDateString(),
                $e['type'],
                $e['ref'],
                $e['description'],
                number_format($e['gross'], 2, '.', ''),
                number_format($e['fee'], 2, '.', ''),
                number_format($e['net'], 2, '.', ''),
            ]);
        }

        rewind($out);

        return stream_get_contents($out);
    }
}
