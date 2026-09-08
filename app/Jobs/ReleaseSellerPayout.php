<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Models\AdminAlert;
use App\Models\OrderItem;
use App\Services\Marketing\LoyaltyService;
use App\Services\Marketing\WalletService;
use App\Services\Payments\TransferPayoutService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReleaseSellerPayout implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [60, 300, 900, 3600, 21600];

    public function __construct(public int $orderItemId) {}

    public function handle(TransferPayoutService $payoutService, WalletService $wallet, LoyaltyService $loyalty): void
    {
        $orderItem = OrderItem::with('seller.sellerProfile', 'order.buyer')->find($this->orderItemId);

        if (! $orderItem || $orderItem->status !== OrderStatus::Delivered) {
            return; // guard against stale/duplicate dispatch
        }

        if ($orderItem->payout_released_at) {
            return; // idempotency guard — never double-pay
        }

        $profile = $orderItem->seller->sellerProfile;

        if (! $profile?->stripe_account_id || ! $profile->stripe_onboarding_complete) {
            // Seller's payout account isn't ready — flag for admin, don't lose the money silently.
            AdminAlert::create([
                'type' => 'payout_blocked',
                'message' => "Order item #{$orderItem->id} delivered but seller has no completed Stripe account.",
                'related_order_item_id' => $orderItem->id,
            ]);

            $this->release(3600); // retry in an hour

            return;
        }

        $transfer = $payoutService->transferToSeller($orderItem, $profile->stripe_account_id);

        $orderItem->update([
            'status' => OrderStatus::PayoutReleased,
            'payout_released_at' => now(),
        ]);

        // Buyer cashback + lifetime-spend/tier — same confirmed-delivery trigger as
        // the seller payout, so the two can never drift out of sync. The
        // cashback_credited_at guard keeps this retry-safe.
        $buyer = $orderItem->order->buyer;

        if ($buyer && ! $orderItem->cashback_credited_at) {
            $rate = $loyalty->cashbackRate($buyer);
            $cashbackAmount = round($orderItem->line_total * ($rate / 100), 2);

            if ($cashbackAmount > 0) {
                $wallet->credit(
                    $buyer,
                    $cashbackAmount,
                    'cashback',
                    "Cashback for order {$orderItem->order->order_number}",
                    $orderItem
                );

                $orderItem->update([
                    'cashback_amount' => $cashbackAmount,
                    'cashback_credited_at' => now(),
                ]);
            }

            $buyer->increment('lifetime_spend', $orderItem->line_total);
            $loyalty->recalculateTier($buyer->fresh());
        }

        $orderItem->statusHistory()->create([
            'status' => OrderStatus::PayoutReleased->value,
            'note' => "Stripe transfer {$transfer->id} for \${$orderItem->seller_payout_amount}; cashback \${$orderItem->cashback_amount} credited",
        ]);
    }
}
