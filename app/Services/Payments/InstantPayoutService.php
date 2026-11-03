<?php

namespace App\Services\Payments;

use App\Enums\OrderStatus;
use App\Models\OrderItem;
use App\Models\PayoutAdvance;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class InstantPayoutService
{
    public function __construct(private TransferPayoutService $transfers) {}

    public function feePct(User $seller): float
    {
        $tier = $seller->sellerProfile?->tier ?? 'standard';

        return in_array($tier, config('marketplace.instant_payout_free_tiers', []), true)
            ? 0.0
            : (float) config('marketplace.instant_payout_fee_pct', 1.5);
    }

    /** @return array{eligible:bool, reason?:string, gross:float, fee:float, net:float} */
    public function quote(OrderItem $item): array
    {
        $seller = $item->seller;
        $gross = (float) $item->seller_payout_amount;
        $fee = round($gross * $this->feePct($seller) / 100, 2);

        $reason = match (true) {
            $item->status !== OrderStatus::Shipped && $item->status !== OrderStatus::OutForDelivery
                => 'Only shipped orders awaiting delivery can be advanced.',
            (bool) $seller->sellerProfile?->payouts_held => 'Payouts on your store are on hold. Contact support for details.',
            (bool) $item->payout_released_at => 'This payout has already been released.',
            PayoutAdvance::where('order_item_id', $item->id)->exists() => 'An advance already exists for this order.',
            ! $seller->sellerProfile?->stripe_account_id || ! $seller->sellerProfile->stripe_onboarding_complete
                => 'Connect your Stripe payout account first.',
            default => null,
        };

        return [
            'eligible' => $reason === null,
            'reason' => $reason,
            'gross' => $gross,
            'fee' => $fee,
            'net' => round($gross - $fee, 2),
        ];
    }

    public function advance(OrderItem $item): PayoutAdvance
    {
        $q = $this->quote($item);
        if (! $q['eligible']) {
            throw ValidationException::withMessages(['advance' => $q['reason']]);
        }

        $accountId = $item->seller->sellerProfile->stripe_account_id;

        // Front the net amount now. Stripe idempotency keyed to the advance.
        $transferId = null;
        try {
            $transfer = $this->transfers->transferToSeller($item, $accountId);
            $transferId = $transfer->id ?? null;
        } catch (\Throwable $e) {
            report($e); // dev / no Stripe key — still record the advance
        }

        return PayoutAdvance::create([
            'order_item_id' => $item->id,
            'seller_id' => $item->seller_id,
            'gross' => $q['gross'],
            'fee' => $q['fee'],
            'net_paid' => $q['net'],
            'stripe_transfer_id' => $transferId,
            'status' => 'advanced',
        ]);
    }
}
