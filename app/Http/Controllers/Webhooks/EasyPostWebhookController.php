<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ReleaseSellerPayout;
use App\Models\OrderItem;
use App\Support\Heartbeat;
use Illuminate\Http\Request;

class EasyPostWebhookController extends Controller
{
    public function handle(Request $request)
    {
        // "Delivered" releases a seller's money, so only EasyPost itself may say it.
        if (! $this->signatureIsValid($request)) {
            return response('Invalid signature', 401);
        }

        Heartbeat::beat('easypost-webhook');

        $payload = $request->all();

        if (($payload['description'] ?? null) !== 'tracker.updated') {
            return response()->json(['ok' => true]);
        }

        $tracker = $payload['result'] ?? [];
        $trackingNumber = $tracker['tracking_code'] ?? null;
        $status = $tracker['status'] ?? null;

        if (! $trackingNumber) {
            return response()->json(['ok' => true]);
        }

        // Only lines on their way move. A late carrier update must never bring back a line that was
        // refunded, cancelled or returned, because "delivered" would then pay the seller for it.
        // One tracking number can cover several lines of a combined shipment.
        $items = OrderItem::where('tracking_number', $trackingNumber)
            ->whereIn('status', [OrderStatus::Shipped, OrderStatus::OutForDelivery])
            ->get();

        foreach ($items as $orderItem) {
            if ($status === 'delivered') {
                $orderItem->update([
                    'status' => OrderStatus::Delivered,
                    'delivered_at' => now(),
                ]);
                app(\App\Services\Integrations\WebhookDispatcher::class)->orderEvent('order.delivered', [$orderItem->fresh('order')]);
                ReleaseSellerPayout::dispatch($orderItem->id);
            } elseif ($status === 'out_for_delivery') {
                $orderItem->update(['status' => OrderStatus::OutForDelivery]);
            }
        }

        // One "delivered" email per package (a tracking number can cover several lines).
        if ($status === 'delivered' && $items->isNotEmpty()) {
            foreach ($items->groupBy('order_id') as $package) {
                $order = $package->first()->order;
                try {
                    $mail = new \App\Notifications\OrderDelivered($order, $package->values());
                    if ($order->buyer) {
                        $order->buyer->notify($mail);
                    } elseif ($order->guest_email) {
                        \Illuminate\Support\Facades\Notification::route('mail', $order->guest_email)->notify($mail);
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        return response()->json(['ok' => true]);
    }

    /** Same check as EasyPost's own libraries. With no secret set, every call is refused. */
    private function signatureIsValid(Request $request): bool
    {
        $secret = (string) config('services.easypost.webhook_secret');
        $signature = (string) $request->header('X-Hmac-Signature');

        if ($secret === '' || $signature === '') {
            return false;
        }

        if (class_exists(\Normalizer::class)) {
            $secret = \Normalizer::normalize($secret, \Normalizer::FORM_KD) ?: $secret;
        }

        return hash_equals('hmac-sha256-hex='.hash_hmac('sha256', $request->getContent(), $secret), $signature);
    }
}
