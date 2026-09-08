<?php

namespace App\Http\Controllers\Storefront;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ReleaseSellerPayout;
use App\Models\Order;
use App\Models\OrderItem;

class OrderController extends Controller
{
    public function index()
    {
        $orders = Order::where('buyer_id', auth()->id())
            ->with('items')
            ->latest()
            ->paginate(15);

        return view('storefront.orders.index', compact('orders'));
    }

    public function show(Order $order)
    {
        abort_unless($order->buyer_id === auth()->id(), 403);

        $order->load('items');

        return view('storefront.orders.show', compact('order'));
    }

    public function confirmDelivery(OrderItem $orderItem)
    {
        abort_unless($orderItem->order->buyer_id === auth()->id(), 403);
        abort_unless(in_array($orderItem->status->value, ['shipped', 'out_for_delivery'], true), 400);

        $orderItem->update([
            'status' => OrderStatus::Delivered,
            'delivered_at' => now(),
        ]);

        $orderItem->statusHistory()->create([
            'status' => OrderStatus::Delivered->value,
            'note' => 'Buyer confirmed delivery.',
            'changed_by' => auth()->id(),
        ]);

        ReleaseSellerPayout::dispatch($orderItem->id);

        return back()->with('success', 'Delivery confirmed — thanks!');
    }
}
