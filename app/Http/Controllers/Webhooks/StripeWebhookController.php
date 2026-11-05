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
            // Ad credit bought via Stripe Checkout — grant it even if the seller closed the tab.
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => ($event->data->object->metadata['kind'] ?? null) === 'ad_credit'
                ? app(\App\Services\Seller\AdCreditPurchaseService::class)->fulfil($event->data->object->id)
                : null,
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

        // Paid after we'd already cancelled it as unpaid (its stock is back on sale):
        // give the money straight back rather than fulfil an order we can't stand behind.
        if ($order->status === OrderStatus::Cancelled) {
            app(\App\Services\Payments\RefundService::class)->refundAmount(
                $order, (float) ($paymentIntent->amount_received ?? 0) / 100, ['kind' => 'late_payment_on_cancelled_order']
            );
            AdminAlert::create([
                'type' => 'late_payment_refunded',
                'message' => "Order {$order->order_number} was paid after it had been cancelled as unpaid — the payment was refunded automatically.",
            ]);

            return;
        }

        $order->update(['status' => OrderStatus::Paid, 'placed_at' => $order->placed_at ?? now()]);
        $order->items()->update(['status' => OrderStatus::Paid]);
        app(\App\Services\Integrations\WebhookDispatcher::class)->orderEvent('order.paid', $order->items()->with('order')->get());
        app(\App\Services\Tax\SalesTaxService::class)->recordSale($order);

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
        $order = Order::with('items')->where('stripe_payment_intent_id', $paymentIntent->id)->first();
        if (! $order || $order->status !== OrderStatus::Placed) {
            return;
        }

        // A declined card doesn't end the order: the buyer usually just tries another card
        // on the same payment page. Note it; if they never pay, UnpaidOrderService cancels
        // the order and frees the stock after the grace period.
        foreach ($order->items as $item) {
            $item->statusHistory()->create([
                'status' => 'payment_failed',
                'note' => 'Card payment attempt failed'.(($paymentIntent->last_payment_error->message ?? null) ? ': '.$paymentIntent->last_payment_error->message : ''),
            ]);
        }
    }

    protected function onConnectAccountUpdated($account): void
    {
        SellerProfile::where('stripe_account_id', $account->id)
            ->update(['stripe_onboarding_complete' => $account->details_submitted && $account->charges_enabled]);
    }
}
