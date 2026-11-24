<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Cart\CartService;
use App\Services\Marketing\CouponService;
use App\Services\Orders\CheckoutAttribution;
use App\Services\Orders\CheckoutProblem;
use App\Services\Orders\CheckoutService;
use App\Services\Shipping\ShippingEstimateService;
use App\Services\Tax\SalesTaxService;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function index(CartService $cart, CouponService $coupons, ShippingEstimateService $shipping)
    {
        $lines = $cart->lines();
        $subtotal = (float) $lines->sum('line_total');

        $discount = 0.0;
        if ($code = session('checkout.coupon_code')) {
            $result = $coupons->validate($code, auth()->user(), $subtotal, $lines);
            $discount = $result['valid'] ? $result['discount'] : 0.0;
        }

        $ship = $shipping->estimate($lines, auth()->user());
        $bundle = $cart->bundleDiscount();

        return view('storefront.checkout.index', [
            'lines' => $lines,
            'grouped' => CheckoutService::groupBySeller($lines),
            'subtotal' => $subtotal,
            'discount' => $discount,
            'bundleSavings' => $bundle['total'],
            'bundlesApplied' => $bundle['applied'],
            'shipping' => $ship['shipping'],
            'shippingFree' => $ship['free'],
            'couponCode' => session('checkout.coupon_code'),
            'taxCollecting' => app(SalesTaxService::class)->enabled(),
            'fit' => app(\App\Services\FitGuarantee::class)->check($lines),
        ]);
    }

    public function applyCoupon(Request $request, CouponService $coupons, CartService $cart)
    {
        $request->validate(['code' => 'required|string|max:64']);

        $result = $coupons->validate($request->code, auth()->user(), $cart->subtotal(), $cart->lines());

        if (! $result['valid']) {
            return back()->withErrors(['coupon' => $result['message']]);
        }

        session(['checkout.coupon_code' => strtoupper($request->code)]);

        return back()->with('success', 'Coupon applied!');
    }

    public function store(Request $request, CartService $cart, CheckoutService $checkout)
    {
        if ($cart->isEmpty()) {
            return redirect()->route('cart.index')->with('status', 'Your cart is empty.');
        }

        $data = $request->validate(
            CheckoutService::addressRules(guest: ! auth()->check()) + CheckoutService::billingRules(),
            CheckoutService::addressMessages() + CheckoutService::billingMessages(),
        );

        try {
            $order = $checkout->place(
                $cart, auth()->user(), $data, session('checkout.coupon_code'),
                auth()->check() && $request->boolean('use_credits'),
                CheckoutAttribution::cookiesFrom($request),
                CheckoutService::billingFrom($data),
            );
        } catch (CheckoutProblem $problem) {
            return $problem->field === 'cart'
                ? redirect()->route('cart.index')->with('status', $problem->getMessage())
                : back()->withInput()->withErrors([$problem->field => $problem->getMessage()]);
        }

        session()->forget('checkout.coupon_code');

        $paymentIntent = $checkout->pay($order);
        if (! $paymentIntent) {
            return redirect()->route('checkout.confirmation', $order->order_number)
                ->with('success', 'Order placed — fully covered by your credits.');
        }

        session(['checkout.last_order' => $order->order_number]);

        return view('storefront.checkout.pay', [
            'order' => $order,
            'clientSecret' => $paymentIntent->client_secret,
        ]);
    }

    public function confirmation(string $orderNumber)
    {
        $order = Order::query()
            ->where('order_number', $orderNumber)
            ->with('items')
            ->firstOrFail();

        // Guests may only see the confirmation right after placing (session hint).
        abort_unless(
            $order->buyer_id === auth()->id()
            || session('checkout.last_order') === $order->order_number
            || (auth()->check() && auth()->user()->hasAnyRole(['super_admin', 'support_agent'])),
            403
        );

        return view('storefront.checkout.confirmation', compact('order'));
    }

    /** "(555) 123-4567" → "+15551234567"; other countries keep their own digits with a "+". */
    public static function cleanPhone(string $phone): string
    {
        return CheckoutService::cleanPhone($phone);
    }
}
