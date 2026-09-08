<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ReleaseSellerPayout;
use App\Models\OrderItem;
use Illuminate\Http\Request;

class EasyPostWebhookController extends Controller
{
    public function handle(Request $request)
    {
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

        $orderItem = OrderItem::where('tracking_number', $trackingNumber)->first();

        if (! $orderItem) {
            return response()->json(['ok' => true]);
        }

        if ($status === 'delivered' && $orderItem->status->value !== 'delivered') {
            $orderItem->update([
                'status' => OrderStatus::Delivered,
                'delivered_at' => now(),
            ]);
            ReleaseSellerPayout::dispatch($orderItem->id);
        } elseif ($status === 'out_for_delivery') {
            $orderItem->update(['status' => OrderStatus::OutForDelivery]);
        }

        return response()->json(['ok' => true]);
    }
}
