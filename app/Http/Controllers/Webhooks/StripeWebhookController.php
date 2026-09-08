<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\AdminAlert;
use App\Models\Order;
use App\Models\SellerProfile;
use App\Models\User;
use App\Notifications\NewOrderReceived;
use App\Services\AI\FraudScoringService;
use App\Services\Marketing\ReferralService;
use Illuminate\Http\Request;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    public function handle(Request $request)
    {
        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature') ?? '',
                config('services.stripe.webhook_secret') ?? ''
            );
        } catch (\Throwable $e) {
            return response('Invalid signature', 400);
        }

        match ($event->type) {
            'payment_intent.succeeded' => $this->onPaymentSucceeded($event->data->object),
            'payment_intent.payment_failed' => $this->onPaymentFailed($event->data->object),
            'account.updated' => $this->onConnectAccountUpdated($event->data->object),
            default => null,
        };

        return response()->json(['received' => true]);
    }

    protected function onPaymentSucceeded($paymentIntent): void
    {
        $order = Order::with('items')->where('stripe_payment_intent_id', $paymentIntent->id)->first();

        if (! $order || $order->status === OrderStatus::Paid) {
            return; // idempotency guard — webhooks can be delivered more than once
        }

        $order->update(['status' => OrderStatus::Paid, 'placed_at' => $order->placed_at ?? now()]);
        $order->items()->update(['status' => OrderStatus::Paid]);

        // Referral bonus is gated on a real first paid order (not signup) to
        // stop reward farming from accounts that never buy.
        if ($order->buyer) {
            app(ReferralService::class)->completeIfEligible($order->buyer);
        }

        // Notify sellers a paid order is waiting for them to accept.
        foreach ($order->items->groupBy('seller_id') as $sellerId => $items) {
            User::find($sellerId)?->notify(new NewOrderReceived($order));
        }

        // Fraud/risk scoring — flagged orders surface in the Phase 5 admin alert inbox.
        $fraud = app(FraudScoringService::class)->score($order);

        if ($fraud['flagged']) {
            AdminAlert::create([
                'type' => 'fraud_review',
                'message' => "Order {$order->order_number} flagged, risk score {$fraud['risk_score']}: ".json_encode($fraud['signals']),
            ]);
        }
    }

    protected function onPaymentFailed($paymentIntent): void
    {
        $order = Order::where('stripe_payment_intent_id', $paymentIntent->id)->first();
        $order?->update(['status' => OrderStatus::Cancelled]);
    }

    protected function onConnectAccountUpdated($account): void
    {
        SellerProfile::where('stripe_account_id', $account->id)
            ->update(['stripe_onboarding_complete' => $account->details_submitted && $account->charges_enabled]);
    }
}
