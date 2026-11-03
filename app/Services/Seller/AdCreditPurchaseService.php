<?php

namespace App\Services\Seller;

use App\Models\AdCreditPurchase;
use App\Models\User;
use App\Services\Payments\AdCreditGateway;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Sellers buy ad credit with a card through Stripe Checkout. Credit is granted
 * only once Stripe confirms the payment — from the webhook or from the seller
 * landing back on the success page, whichever comes first — and never twice.
 */
class AdCreditPurchaseService
{
    public const MIN = 10;

    public const MAX = 2000;

    public const PACKAGES = [25, 50, 100, 250, 500];

    public function __construct(private AdCreditGateway $gateway, private BoostService $boosts) {}

    /** Start a purchase; returns the Stripe-hosted payment page URL to send the seller to. */
    public function start(User $seller, ?User $actor, float $amount): string
    {
        if (! $this->gateway->isConfigured()) {
            throw new RuntimeException('Card payments are not configured yet.');
        }
        $amount = round($amount, 2);
        if ($amount < self::MIN || $amount > self::MAX) {
            throw new RuntimeException('Choose an amount between $'.self::MIN.' and $'.number_format(self::MAX).'.');
        }

        $purchase = AdCreditPurchase::create([
            'seller_id' => $seller->id,
            'purchased_by' => $actor?->id ?? $seller->id,
            'amount' => $amount,
            'status' => 'pending',
        ]);

        $session = $this->gateway->createSession(
            $purchase,
            route('seller.ad-credit.return').'?session_id={CHECKOUT_SESSION_ID}',
            route('seller.ad-credit.index', ['cancelled' => 1]),
            $seller->email,
        );

        $purchase->update(['stripe_session_id' => $session['id']]);

        return $session['url'];
    }

    /**
     * Confirm a Checkout Session with Stripe and grant the credit if it's paid.
     * Safe to call any number of times for the same session.
     */
    public function fulfil(string $sessionId): ?AdCreditPurchase
    {
        $purchase = AdCreditPurchase::where('stripe_session_id', $sessionId)->first();
        if (! $purchase) {
            return null;
        }
        if ($purchase->status === 'paid') {
            return $purchase;
        }

        $session = $this->gateway->retrieveSession($sessionId);
        if (! $session['paid']) {
            return $purchase;
        }

        return DB::transaction(function () use ($purchase, $session) {
            // Re-read under a row lock so the webhook and the return page can't both grant.
            $locked = AdCreditPurchase::whereKey($purchase->id)->lockForUpdate()->first();
            if ($locked->status === 'paid') {
                return $locked;
            }

            $locked->update([
                'status' => 'paid',
                'paid_at' => now(),
                'stripe_payment_intent_id' => $session['payment_intent'],
            ]);

            $this->boosts->grantCredit(
                $locked->seller,
                (float) $locked->amount,
                'Ad credit purchase — card',
                'topup',
            );

            return $locked;
        });
    }
}
