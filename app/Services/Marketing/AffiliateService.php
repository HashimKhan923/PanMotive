<?php

namespace App\Services\Marketing;

use App\Models\Affiliate;
use App\Models\AffiliateConversion;
use App\Models\AffiliateProgram;
use App\Models\Order;
use App\Models\User;
use App\Services\Marketing\WalletService;
use Illuminate\Support\Facades\DB;

class AffiliateService
{
    public function __construct(private WalletService $wallet) {}

    public function join(AffiliateProgram $program, string $name, string $email, ?string $channel, ?User $user = null): Affiliate
    {
        $affiliate = Affiliate::firstOrCreate(
            ['affiliate_program_id' => $program->id, 'email' => strtolower($email)],
            [
                'user_id' => $user?->id,
                'name' => $name,
                'channel' => $channel,
                'status' => $program->auto_approve ? 'approved' : 'pending',
            ]
        );

        if ($user && ! $affiliate->user_id) {
            $affiliate->update(['user_id' => $user->id]);
        }

        return $affiliate;
    }

    /**
     * Record a commission for an order that came through an affiliate link. The commission is the
     * seller's cost, so it's withheld from their payout on the attributed lines right away (like the
     * Offsite Ads fee) — when the affiliate is paid later, the money has already been set aside.
     */
    public function attribute(Order $order, string $code): ?AffiliateConversion
    {
        $affiliate = Affiliate::with('program')->where('code', $code)->where('status', 'approved')->first();
        if (! $affiliate || ! $affiliate->program->is_active) {
            return null;
        }

        // Only the affiliated seller's portion of the order earns commission.
        $order->loadMissing('items');
        $lines = $order->items->where('seller_id', $affiliate->program->seller_id)
            ->reject(fn ($i) => in_array($i->status->value, ['cancelled', 'refunded'], true))->values();
        $sellerValue = round((float) $lines->sum('line_total'), 2);
        if ($sellerValue <= 0 || $affiliate->user_id === $order->buyer_id) {
            return null;
        }

        $commission = round($sellerValue * (float) $affiliate->program->commission_pct / 100, 2);

        return DB::transaction(function () use ($affiliate, $order, $sellerValue, $commission, $lines) {
            $conversion = AffiliateConversion::firstOrCreate(
                ['affiliate_id' => $affiliate->id, 'order_id' => $order->id],
                ['order_value' => $sellerValue, 'commission' => $commission, 'status' => 'pending']
            );

            if ($conversion->wasRecentlyCreated) {
                $affiliate->increment('conversions');
                $affiliate->increment('earnings', $commission);

                // Spread over the lines by value; the last line takes the rounding remainder.
                $remaining = $commission;
                foreach ($lines as $i => $line) {
                    $share = $i === $lines->count() - 1
                        ? $remaining
                        : round($commission * (float) $line->line_total / max($sellerValue, 0.01), 2);
                    $remaining = round($remaining - $share, 2);
                    $line->update([
                        'affiliate_fee' => $share,
                        'seller_payout_amount' => round((float) $line->seller_payout_amount - $share, 2),
                    ]);
                }
            }

            return $conversion;
        });
    }

    /**
     * Move pending commissions to "approved" (payable) once every one of the seller's lines in the
     * order was delivered and the return window has passed — and void them if those lines were
     * cancelled or refunded instead. Another seller's items in the same order don't count.
     */
    public function approveDelivered(): int
    {
        $count = 0;
        $holdDays = (int) config('marketplace.marketing.affiliate_hold_days', 30);

        AffiliateConversion::where('status', 'pending')
            ->with('affiliate.program', 'order.items')
            ->chunkById(200, function ($rows) use (&$count, $holdDays) {
                foreach ($rows as $c) {
                    $lines = $c->order?->items->where('seller_id', $c->affiliate?->program?->seller_id);
                    if (! $lines || $lines->isEmpty()) {
                        continue;
                    }

                    $statuses = $lines->map(fn ($l) => $l->status->value);
                    $dead = $statuses->every(fn ($st) => in_array($st, ['cancelled', 'refunded'], true));
                    if ($dead) {
                        $this->void($c);

                        continue;
                    }

                    $kept = $lines->reject(fn ($l) => in_array($l->status->value, ['cancelled', 'refunded'], true));
                    $settled = $kept->every(fn ($l) => in_array($l->status->value, ['delivered', 'payout_released'], true)
                        && ($l->delivered_at ?? $l->shipped_at ?? $l->updated_at)?->lt(now()->subDays($holdDays)));
                    if (! $settled) {
                        continue;
                    }

                    // Partly refunded: commission only on what the buyer kept.
                    $keptValue = round((float) $kept->sum('line_total'), 2);
                    $commission = round($keptValue * (float) $c->affiliate->program->commission_pct / 100, 2);
                    if ($commission < (float) $c->commission) {
                        $c->affiliate->decrement('earnings', round((float) $c->commission - $commission, 2));
                    }
                    $c->update(['status' => 'approved', 'approved_at' => now(), 'order_value' => $keptValue, 'commission' => min($commission, (float) $c->commission)]);
                    $count++;
                }
            });

        return $count;
    }

    /** The sale fell through — the affiliate earns nothing on it. */
    public function void(AffiliateConversion $conversion): void
    {
        if ($conversion->status !== 'pending') {
            return;
        }
        $conversion->update(['status' => 'reversed']);
        $conversion->affiliate?->decrement('earnings', (float) $conversion->commission);
        $conversion->affiliate?->decrement('conversions');
    }

    /** Seller pays an affiliate — credited as PanMotive store credit. */
    public function payOut(Affiliate $affiliate): float
    {
        $due = AffiliateConversion::where('affiliate_id', $affiliate->id)->where('status', 'approved')->sum('commission');
        $due = round((float) $due, 2);
        if ($due <= 0 || ! $affiliate->user_id) {
            return 0.0;
        }

        DB::transaction(function () use ($affiliate, $due) {
            AffiliateConversion::where('affiliate_id', $affiliate->id)->where('status', 'approved')
                ->update(['status' => 'paid', 'paid_at' => now()]);
            $affiliate->increment('paid_out', $due);

            $this->wallet->credit(
                $affiliate->user, $due, 'referral_bonus',
                "Affiliate commission from {$affiliate->program->seller->sellerProfile?->business_name}"
            );
        });

        return $due;
    }
}
