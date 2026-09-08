<?php

namespace App\Http\Controllers\Storefront;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Notifications\NewOrderReceived;
use App\Services\Cart\CartService;
use App\Services\Marketing\CouponService;
use App\Services\Marketing\ReferralService;
use App\Services\Marketing\WalletService;
use App\Services\Payments\CheckoutPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
    public function index(CartService $cart, CouponService $coupons)
    {
        $lines = $cart->lines();
        $subtotal = (float) $lines->sum('line_total');

        $discount = 0.0;
        if ($code = session('checkout.coupon_code')) {
            $result = $coupons->validate($code, auth()->user(), $subtotal);
            $discount = $result['valid'] ? $result['discount'] : 0.0;
        }

        return view('storefront.checkout.index', [
            'lines' => $lines,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'couponCode' => session('checkout.coupon_code'),
        ]);
    }

    public function applyCoupon(Request $request, CouponService $coupons, CartService $cart)
    {
        $request->validate(['code' => 'required|string|max:64']);

        $result = $coupons->validate($request->code, auth()->user(), $cart->subtotal());

        if (! $result['valid']) {
            return back()->withErrors(['coupon' => $result['message']]);
        }

        session(['checkout.coupon_code' => strtoupper($request->code)]);

        return back()->with('success', 'Coupon applied!');
    }

    public function store(Request $request, CartService $cart, CheckoutPaymentService $payments, CouponService $coupons, WalletService $wallet)
    {
        if ($cart->isEmpty()) {
            return redirect()->route('cart.index')->with('status', 'Your cart is empty.');
        }

        $address = $request->validate([
            'name' => 'required|string|max:255',
            'line1' => 'required|string|max:255',
            'line2' => 'nullable|string|max:255',
            'city' => 'required|string|max:120',
            'state' => 'required|string|max:120',
            'postal_code' => 'required|string|max:20',
            'country' => 'required|string|size:2',
        ]);

        $lines = $cart->lines();

        if ($lines->isEmpty()) {
            return redirect()->route('cart.index')->with('status', 'None of your cart items are available.');
        }

        $subtotal = (float) $lines->sum('line_total');

        // Coupon (revalidated at submit — session value is only a hint).
        $appliedCoupon = null;
        $discountTotal = 0.0;
        if ($code = session('checkout.coupon_code')) {
            $result = $coupons->validate($code, auth()->user(), $subtotal);
            if ($result['valid']) {
                $appliedCoupon = $result['coupon'];
                $discountTotal = $result['discount'];
            }
        }

        // Store credit — capped at 50% of the post-coupon amount.
        $creditsToUse = 0.0;
        if (auth()->check() && $request->boolean('use_credits')) {
            $creditsToUse = $wallet->maxRedeemable(auth()->user(), max(0, $subtotal - $discountTotal));
        }

        $total = round(max(0, $subtotal - $discountTotal - $creditsToUse), 2);

        $order = DB::transaction(function () use ($lines, $address, $subtotal, $discountTotal, $creditsToUse, $total, $appliedCoupon, $coupons, $wallet) {
            $order = Order::create([
                'order_number' => 'PM-'.strtoupper(Str::random(10)),
                'buyer_id' => auth()->id(),
                'status' => OrderStatus::Placed,
                'subtotal' => $subtotal,
                'shipping_total' => 0,
                'tax_total' => 0,
                'discount_total' => $discountTotal,
                'credits_used' => $creditsToUse,
                'coupon_id' => $appliedCoupon?->id,
                'total' => $total,
                'currency' => 'USD',
                'shipping_address' => $address,
                'billing_address' => $address,
            ]);

            foreach ($lines as $line) {
                $product = $line['product'];
                $rate = $product->seller?->sellerProfile?->commissionRate()
                    ?? (float) config('marketplace.default_commission_rate');

                // Commission is snapshotted on the full line total — coupons and
                // credits are platform-funded and never reduce seller payout.
                $split = OrderItem::calculateCommission($line['line_total'], $rate);

                $order->items()->create([
                    'seller_id' => $product->seller_id,
                    'product_id' => $product->id,
                    'product_title' => $product->title,
                    'product_sku' => $product->sku,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'line_total' => $line['line_total'],
                    'commission_rate' => $rate,
                    'platform_fee_amount' => $split['platform_fee_amount'],
                    'seller_payout_amount' => $split['seller_payout_amount'],
                    'status' => OrderStatus::Placed,
                ]);
            }

            if ($appliedCoupon) {
                $coupons->redeem($appliedCoupon, auth()->user(), $order, $discountTotal);
            }

            if ($creditsToUse > 0) {
                $wallet->debit(auth()->user(), $creditsToUse, "Applied to order {$order->order_number}", $order);
            }

            return $order;
        });

        $cart->clear();
        session()->forget('checkout.coupon_code');

        // Fully covered by credits/coupon — no card charge, so there's no
        // PaymentIntent and no webhook; mark it paid inline here.
        if ($order->total <= 0) {
            $order->update(['status' => OrderStatus::Paid, 'placed_at' => now()]);
            $order->items()->update(['status' => OrderStatus::Paid]);

            if ($order->buyer) {
                app(ReferralService::class)->completeIfEligible($order->buyer);
            }
            foreach ($order->items->groupBy('seller_id') as $sellerId => $items) {
                User::find($sellerId)?->notify(new NewOrderReceived($order));
            }

            return redirect()->route('checkout.confirmation', $order->order_number)
                ->with('success', 'Order placed — fully covered by your credits.');
        }

        $paymentIntent = $payments->createPaymentIntent($order);
        $order->update(['stripe_payment_intent_id' => $paymentIntent->id]);

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

        return view('storefront.checkout.confirmation', compact('order'));
    }
}
