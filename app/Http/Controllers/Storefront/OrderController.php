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

    /** Open (or reuse) a message thread with the seller of one line, tied to this order. */
    public function contactSeller(\App\Models\OrderItem $orderItem, \App\Services\MessagingService $messaging)
    {
        $order = $orderItem->order;
        abort_unless($order->buyer_id === auth()->id(), 403);
        abort_unless($orderItem->seller, 404);

        $conversation = $messaging->threadFor(auth()->user(), $orderItem->seller, null, $order);

        return redirect()->route('messages.index', ['c' => $conversation->id]);
    }

    /** Finish paying an order that's still awaiting payment (e.g. a Subscribe & Save order). */
    public function pay(Order $order, \App\Services\Payments\CheckoutPaymentService $payments)
    {
        abort_unless($order->buyer_id === auth()->id(), 403);

        if ($order->status !== OrderStatus::Placed) {
            return redirect()->route('orders.show', $order)
                ->with('status', $order->status === OrderStatus::Cancelled ? 'This order was cancelled.' : 'This order is already paid.');
        }

        try {
            $intent = $payments->intentFor($order);
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('orders.show', $order)->withErrors(['pay' => 'Card payments aren\'t available right now — please try again shortly.']);
        }

        session(['checkout.last_order' => $order->order_number]);

        return view('storefront.checkout.pay', ['order' => $order, 'clientSecret' => $intent->client_secret]);
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
        app(\App\Services\Integrations\WebhookDispatcher::class)->orderEvent('order.delivered', [$orderItem->fresh('order')]);

        ReleaseSellerPayout::dispatch($orderItem->id);

        return back()->with('success', 'Delivery confirmed — thanks!');
    }
}
