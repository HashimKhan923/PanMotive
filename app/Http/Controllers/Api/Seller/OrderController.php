<?php

namespace App\Http\Controllers\Api\Seller;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $items = OrderItem::where('seller_id', $request->user()->id)
            ->with('order:id,order_number,shipping_address,created_at')
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')
            ->paginate(min(100, (int) $request->integer('per_page', 50)));

        return response()->json($items->through(fn ($i) => $this->transform($i)));
    }

    public function show(Request $request, OrderItem $orderItem)
    {
        $this->owner($request, $orderItem);

        return response()->json($this->transform($orderItem->load('order')));
    }

    public function accept(Request $request, OrderItem $orderItem)
    {
        $this->owner($request, $orderItem);
        abort_unless(in_array($orderItem->status, OrderStatus::awaitingAcceptance(), true), 422, 'Order cannot be accepted.');

        $orderItem->update(['status' => OrderStatus::SellerAccepted]);
        $orderItem->statusHistory()->create(['status' => OrderStatus::SellerAccepted->value, 'note' => 'via API']);

        return response()->json($this->transform($orderItem->fresh()->load('order')));
    }

    public function ship(Request $request, OrderItem $orderItem)
    {
        $this->owner($request, $orderItem);
        abort_unless($orderItem->status === OrderStatus::SellerAccepted, 422, 'Order is not ready to ship.');

        $data = $request->validate([
            'carrier' => 'required|string|max:40',
            'tracking_number' => 'required|string|max:60',
        ]);

        $orderItem->update([...$data, 'status' => OrderStatus::Shipped, 'shipped_at' => now()]);
        $orderItem->statusHistory()->create(['status' => OrderStatus::Shipped->value, 'note' => "via API — {$data['carrier']} {$data['tracking_number']}"]);

        app(\App\Services\Integrations\WebhookDispatcher::class)->fire('order.shipped', $orderItem->seller_id, $this->transform($orderItem->fresh()->load('order')));

        return response()->json($this->transform($orderItem->fresh()->load('order')));
    }

    private function owner(Request $request, OrderItem $orderItem): void
    {
        abort_unless($orderItem->seller_id === $request->user()->id, 404);
    }

    private function transform(OrderItem $i): array
    {
        return [
            'id' => $i->id,
            'order_number' => $i->order?->order_number,
            'product_title' => $i->product_title,
            'variant' => $i->variant_label,
            'quantity' => $i->quantity,
            'line_total' => (float) $i->line_total,
            'seller_payout' => (float) $i->seller_payout_amount,
            'status' => $i->status->value,
            'carrier' => $i->carrier,
            'tracking_number' => $i->tracking_number,
            'ship_to' => $i->order?->shipping_address,
            'created_at' => $i->created_at?->toIso8601String(),
        ];
    }
}
