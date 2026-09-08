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
}
