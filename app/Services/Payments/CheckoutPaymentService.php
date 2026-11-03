<?php

namespace App\Services\Payments;

use App\Models\Order;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

class CheckoutPaymentService
{
    protected StripeClient $stripe;

    public function __construct()
    {
        $this->stripe = new StripeClient(config('services.stripe.secret') ?: 'sk_test_placeholder');
    }

    public function createPaymentIntent(Order $order): PaymentIntent
    {
        return $this->stripe->paymentIntents->create([
            'amount' => (int) round($order->total * 100), // cents
            'currency' => strtolower($order->currency),
            'metadata' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ],
            // No destination/application_fee here — funds land on the platform
            // balance. Per-seller transfers happen later via TransferPayoutService.
            'automatic_payment_methods' => ['enabled' => true],
        ]);
    }

    /**
     * The order's payment, reused if it can still be paid — so an order never ends up
     * with two live PaymentIntents that could both be charged.
     */
    public function intentFor(Order $order): PaymentIntent
    {
        if ($order->stripe_payment_intent_id) {
            $existing = $this->stripe->paymentIntents->retrieve($order->stripe_payment_intent_id);
            if (in_array($existing->status, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true)) {
                return $existing;
            }
        }

        $intent = $this->createPaymentIntent($order);
        $order->update(['stripe_payment_intent_id' => $intent->id]);

        return $intent;
    }

    /** Stop an unpaid order's payment from ever being completed (best effort). */
    public function cancelIntent(Order $order): void
    {
        if (! $order->stripe_payment_intent_id) {
            return;
        }

        try {
            $this->stripe->paymentIntents->cancel($order->stripe_payment_intent_id);
        } catch (\Throwable $e) {
            report($e); // already cancelled/succeeded — the webhook guard handles a late success
        }
    }
}
