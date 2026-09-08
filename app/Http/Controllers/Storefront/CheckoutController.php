<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Order;

class CheckoutController extends Controller
{
    public function index()
    {
        return view('storefront.checkout.index');
    }

    public function store()
    {
        // TODO (Phase 3): validate cart, split into Order + per-seller OrderItems,
        // snapshot commission, create the Stripe PaymentIntent, then redirect to
        // checkout.confirmation with the new order number.
        return redirect()
            ->route('cart.index')
            ->with('status', 'Checkout is not wired up yet.');
    }

    public function confirmation(string $orderNumber)
    {
        $order = Order::query()
            ->where('order_number', $orderNumber)
            ->with('items')
            ->firstOrFail();

        return view('storefront.checkout.confirmation', compact('order'));
    }
}
