<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Models\AdminAlert;
use App\Models\OrderItem;
use App\Services\Payments\TransferPayoutService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Supply Network: after the supplier is paid for a delivered resold line, the reseller gets its margin.
 * Same safety rules as the supplier payout: holds, verification, a connected Stripe account, never twice.
 */
class ReleaseResellerMargin implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 8;

    public array $backoff = [60, 300, 900, 3600, 21600, 43200, 86400, 86400];

    public function __construct(public int $orderItemId) {}

    public function handle(TransferPayoutService $payouts): void
    {
        $item = OrderItem::with('reseller.sellerProfile')->find($this->orderItemId);
        if (! $item || ! $item->reseller_id || $item->reseller_paid_at || (float) $item->reseller_margin <= 0) {
            return;
        }
        if ($item->status !== OrderStatus::PayoutReleased) {
            return;   // refunded, disputed or not delivered: the margin isn't earned
        }

        $profile = $item->reseller?->sellerProfile;
        if ($profile?->payouts_held) {
            AdminAlert::firstOrCreate(
                ['type' => 'payout_held', 'related_order_item_id' => $item->id, 'resolved_at' => null],
                ['message' => "Resale margin for order item #{$item->id} is waiting: payouts for {$profile->business_name} are on hold."],
            );

            return;
        }
        if (config('marketplace.kyc.enforce_payouts')
            && \App\Models\SellerVerification::where('seller_id', $item->reseller_id)->where('status', 'approved')->doesntExist()) {
            $this->waitFor("Resale margin for order item #{$item->id} is waiting for the reseller's identity verification.");

            return;
        }
        if (! $profile?->stripe_account_id || ! $profile->stripe_onboarding_complete) {
            $this->waitFor("Resale margin for order item #{$item->id} is waiting: the reseller has no completed Stripe account.");

            return;
        }

        $transfer = $payouts->transferResellerMargin($item, $profile->stripe_account_id);
        $item->update(['reseller_paid_at' => now(), 'reseller_transfer_id' => $transfer->id]);
        $item->statusHistory()->create([
            'status' => $item->status->value,
            'note' => "Resale margin \${$item->reseller_margin} paid to {$profile->business_name} (Stripe transfer {$transfer->id})",
        ]);
    }

    private function waitFor(string $message): void
    {
        AdminAlert::firstOrCreate(
            ['type' => 'payout_blocked', 'related_order_item_id' => $this->orderItemId, 'resolved_at' => null],
            ['message' => $message],
        );
        $this->release(3600);
    }

    public function failed(Throwable $e): void
    {
        AdminAlert::create([
            'type' => 'payout_job_failed',
            'message' => "ReleaseResellerMargin for order item #{$this->orderItemId} failed: {$e->getMessage()}",
            'related_order_item_id' => OrderItem::whereKey($this->orderItemId)->exists() ? $this->orderItemId : null,
        ]);
    }
}
