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
use Illuminate\Support\Facades\DB;
use Throwable;

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
            return; // stale/duplicate dispatch, or already released
        }

        if ($orderItem->payout_released_at) {
            return; // idempotency guard — never double-pay
        }

        $profile = $orderItem->seller->sellerProfile;

        if (! $profile?->stripe_account_id || ! $profile->stripe_onboarding_complete) {
            AdminAlert::create([
                'type' => 'payout_blocked',
                'message' => "Order item #{$orderItem->id} delivered but seller has no completed Stripe account.",
                'related_order_item_id' => $orderItem->id,
            ]);

            $this->release(3600);

            return;
        }

        // 1. Transfer — Stripe idempotency_key ("payout-order-item-{id}") means a
        //    retry returns the same transfer, never a second one.
        $transfer = $payoutService->transferToSeller($orderItem, $profile->stripe_account_id);

        // 2. Buyer cashback + lifetime-spend, all in one transaction and guarded by
        //    cashback_credited_at. Runs BEFORE payout_released_at is set, so if this
        //    step fails the whole job retries safely (step 1 is idempotent, this
        //    step is guarded) instead of silently skipping cashback forever.
        $buyer = $orderItem->order->buyer;

        if ($buyer && ! $orderItem->cashback_credited_at) {
            $rate = $loyalty->cashbackRate($buyer);
            $cashbackAmount = round($orderItem->line_total * ($rate / 100), 2);

            DB::transaction(function () use ($wallet, $buyer, $cashbackAmount, $orderItem) {
                if ($cashbackAmount > 0) {
                    $wallet->credit(
                        $buyer,
                        $cashbackAmount,
                        'cashback',
                        "Cashback for order {$orderItem->order->order_number}",
                        $orderItem
                    );
                }

                $orderItem->update([
                    'cashback_amount' => $cashbackAmount,
                    'cashback_credited_at' => now(),
                ]);

                $buyer->increment('lifetime_spend', $orderItem->line_total);
            });

            $loyalty->recalculateTier($buyer->fresh());
        }

        // 3. Only now mark the payout released — the point of no return.
        $orderItem->update([
            'status' => OrderStatus::PayoutReleased,
            'payout_released_at' => now(),
        ]);

        $orderItem->statusHistory()->create([
            'status' => OrderStatus::PayoutReleased->value,
            'note' => "Stripe transfer {$transfer->id} for \${$orderItem->seller_payout_amount}; cashback \${$orderItem->cashback_amount} credited",
        ]);
    }

    public function failed(Throwable $e): void
    {
        AdminAlert::create([
            'type' => 'payout_job_failed',
            'message' => "ReleaseSellerPayout for order item #{$this->orderItemId} failed after retries: {$e->getMessage()}",
            'related_order_item_id' => OrderItem::whereKey($this->orderItemId)->exists() ? $this->orderItemId : null,
        ]);
    }
}
