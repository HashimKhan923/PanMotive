<?php

namespace App\Http\Controllers\Seller;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Models\PayoutAdvance;
use App\Models\SellerVerification;
use App\Services\Seller\SellerLedgerService;

class PayoutController extends Controller
{
    public function index(SellerLedgerService $ledger)
    {
        $sellerId = auth()->id();
        $profile = auth()->user()->sellerProfile;
        $status = $ledger->payoutStatus($sellerId);

        // Everything that can stop money reaching the bank, in the order the seller should fix it.
        $blockers = [];
        if ($profile?->payouts_held) {
            $blockers[] = ['title' => 'Payouts are on hold', 'body' => 'PanMotive has paused your payouts: '.$profile->payouts_held_reason.'. Your earnings are safe.', 'url' => route('seller.support.index'), 'cta' => 'Contact support'];
        }
        if (! $profile?->stripe_account_id || ! $profile->stripe_onboarding_complete) {
            $blockers[] = ['title' => 'Connect your payout account', 'body' => 'Set up Stripe so we can transfer your earnings to your bank.', 'url' => route('seller.onboarding.start'), 'cta' => 'Connect now'];
        }
        if (config('marketplace.kyc.enforce_payouts')
            && SellerVerification::where('seller_id', $sellerId)->where('status', 'approved')->doesntExist()) {
            $blockers[] = ['title' => 'Verify your identity', 'body' => 'Payouts are paused until your identity verification is approved.', 'url' => route('seller.verification.index'), 'cta' => 'Verify'];
        }

        $fees = OrderItem::where('seller_id', $sellerId)
            ->whereIn('status', [OrderStatus::Delivered->value, OrderStatus::PayoutReleased->value])
            ->selectRaw('COALESCE(SUM(platform_fee_amount),0) platform, COALESCE(SUM(offsite_ad_fee),0) offsite, COALESCE(SUM(affiliate_fee),0) affiliate, COALESCE(SUM(label_fee),0) labels')
            ->first();

        $autoDays = (int) config('marketplace.auto_confirm_delivery_days', 7);
        $pending = OrderItem::where('seller_id', $sellerId)
            ->whereIn('status', [OrderStatus::Shipped->value, OrderStatus::OutForDelivery->value, OrderStatus::Delivered->value,
                OrderStatus::ReturnRequested->value, OrderStatus::ReturnApproved->value, OrderStatus::Disputed->value])
            ->whereNull('payout_released_at')
            ->with('order:id,order_number')
            ->orderBy('shipped_at')
            ->get()
            ->each(function (OrderItem $i) use ($autoDays, $blockers) {
                // When this line's money should move, and why it might not.
                $i->release_note = match (true) {
                    in_array($i->status, [OrderStatus::ReturnRequested, OrderStatus::ReturnApproved, OrderStatus::Disputed], true) => 'On hold — return/dispute open',
                    $blockers !== [] => 'Waiting on: '.$blockers[0]['title'],
                    $i->status === OrderStatus::Delivered => 'Releasing now',
                    $i->shipped_at !== null => 'By '.$i->shipped_at->copy()->addDays($autoDays)->format('M j').' (or sooner if the buyer confirms)',
                    default => 'After delivery',
                };
            });

        return view('seller.payouts.index', [
            'status' => $status,
            'fees' => [
                'platform' => (float) $fees->platform,
                'offsite' => (float) $fees->offsite,
                'affiliate' => (float) $fees->affiliate,
                'labels' => (float) $fees->labels,
                'instant' => (float) PayoutAdvance::where('seller_id', $sellerId)->sum('fee'),
            ],
            'blockers' => $blockers,
            'pending' => $pending,
            'released' => OrderItem::where('seller_id', $sellerId)
                ->where('status', OrderStatus::PayoutReleased->value)
                ->with('order:id,order_number')
                ->orderByDesc('payout_released_at')
                ->paginate(25),
            'reversed' => OrderItem::where('seller_id', $sellerId)
                ->where('status', OrderStatus::Refunded->value)->whereNotNull('payout_released_at')
                ->with('order:id,order_number')->latest('updated_at')->take(20)->get(),
            'advances' => PayoutAdvance::where('seller_id', $sellerId)
                ->with('orderItem.order:id,order_number')
                ->latest()->take(20)->get(),
            'profile' => $profile,
            'autoDays' => $autoDays,
        ]);
    }
}
