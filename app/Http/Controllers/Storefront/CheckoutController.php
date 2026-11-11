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
use App\Services\Shipping\ShippingEstimateService;
use App\Services\Tax\SalesTaxService;
use App\Services\Tax\SalesTaxUnavailable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
            'grouped' => $this->groupBySeller($lines),
            'subtotal' => $subtotal,
            'discount' => $discount,
            'bundleSavings' => $bundle['total'],
            'bundlesApplied' => $bundle['applied'],
            'shipping' => $ship['shipping'],
            'shippingFree' => $ship['free'],
            'couponCode' => session('checkout.coupon_code'),
            'taxCollecting' => app(SalesTaxService::class)->enabled(),
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

    public function store(
        Request $request,
        CartService $cart,
        CheckoutPaymentService $payments,
        CouponService $coupons,
        WalletService $wallet,
        ShippingEstimateService $shipping,
        SalesTaxService $salesTax,
    ) {
        if ($cart->isEmpty()) {
            return redirect()->route('cart.index')->with('status', 'Your cart is empty.');
        }

        $rules = [
            'name' => 'required|string|max:255',
            'line1' => 'required|string|max:255',
            'line2' => 'nullable|string|max:255',
            'city' => 'required|string|max:120',
            'state' => 'required|string|max:120',
            'postal_code' => 'required|string|max:20',
            'country' => 'required|string|size:2',
        ];
        if (! auth()->check()) {
            $rules['email'] = 'required|email|max:255';
        }
        $data = $request->validate($rules);
        $address = collect($data)->except('email')->all();

        $lines = $cart->lines();
        if ($lines->isEmpty()) {
            return redirect()->route('cart.index')->with('status', 'None of your cart items are available.');
        }

        // Store the state as its 2-letter code ("California" → "CA") so restrictions, surcharges,
        // labels and tax all see the same value.
        $address['state'] = ShippingEstimateService::normalizeState($address['state'] ?? null);

        // Sellers away with sales paused aren't taking new orders.
        $away = $lines->filter(fn ($l) => $l['product']->seller?->sellerProfile?->salesPaused())
            ->map(fn ($l) => $l['product']->title)->unique()->values();
        if ($away->isNotEmpty()) {
            return back()->withInput()->withErrors([
                'name' => 'The seller of these items is away and not taking orders right now: '.$away->implode(', ').'. Remove them from your cart or save them for later.',
            ]);
        }

        // A seller who blocked this buyer won't sell to them.
        if (auth()->check()) {
            $refused = $lines->filter(fn ($l) => \App\Models\SellerBlockedBuyer::isBlocked($l['product']->seller_id, auth()->id()))
                ->map(fn ($l) => $l['product']->title)->unique()->values();
            if ($refused->isNotEmpty()) {
                return back()->withInput()->withErrors([
                    'name' => "The seller of these items isn't accepting orders from your account: ".$refused->implode(', ').'. Remove them from your cart to continue.',
                ]);
            }
        }

        // Sellers can refuse certain states (e.g. CARB-restricted parts to California, or AK/HI).
        $blocked = $shipping->restrictedTitles($lines, $address['state']);
        if ($blocked) {
            return back()->withInput()->withErrors([
                'state' => 'These items can\'t be shipped to '.$address['state'].': '.implode(', ', $blocked)
                    .'. Remove them from your cart or use a different address.',
            ]);
        }

        $subtotal = (float) $lines->sum('line_total');
        $shippingTotal = $shipping->estimate($lines, auth()->user(), $address['state'] ?? null)['shipping'];

        $appliedCoupon = null;
        $couponDiscount = 0.0;
        if ($code = session('checkout.coupon_code')) {
            $result = $coupons->validate($code, auth()->user(), $subtotal, $lines);
            if ($result['valid']) {
                $appliedCoupon = $result['coupon'];
                $couponDiscount = $result['discount'];
            }
        }

        // Seller bundle savings (auto-applied when the full set is in the cart).
        $bundle = $cart->bundleDiscount($lines);
        $discountTotal = round($couponDiscount + $bundle['total'], 2);

        // The part of those discounts the SELLER funds (their bundles and their own codes) comes
        // off their order lines; only platform-wide coupons stay an order-level discount we fund.
        $lineDiscounts = app(\App\Services\Orders\SellerDiscountAllocator::class)
            ->allocate($lines, $bundle['by_product'], $appliedCoupon, $couponDiscount);

        $creditsToUse = 0.0;
        if (auth()->check() && $request->boolean('use_credits')) {
            $creditsToUse = $wallet->maxRedeemable(auth()->user(), max(0, $subtotal - $discountTotal));
        }

        // Tax is charged on what's actually paid for the goods: store credit is a way of paying, not a
        // discount, so it doesn't lower the tax. Worked out before anything is saved, so a problem
        // here leaves the cart untouched.
        $netLines = $lines->map(fn ($line, $i) => round((float) $line['line_total'] - (float) ($lineDiscounts[$i] ?? 0), 2))->all();
        $taxable = $salesTax->taxableAmounts($netLines, max(0, $discountTotal - (float) array_sum((array) $lineDiscounts)));
        try {
            $tax = $salesTax->calculate($taxable, $shippingTotal, $address);
        } catch (SalesTaxUnavailable) {
            return back()->withInput()->withErrors([
                'postal_code' => 'We couldn\'t work out the sales tax for this address. Check the ZIP code and state, then try again.',
            ]);
        }

        $total = round(max(0, $subtotal + $shippingTotal + $tax->total - $discountTotal - $creditsToUse), 2);

        $order = DB::transaction(function () use ($lines, $lineDiscounts, $couponDiscount, $address, $data, $subtotal, $shippingTotal, $discountTotal, $creditsToUse, $total, $tax, $appliedCoupon, $coupons, $wallet) {
            $order = Order::create([
                'order_number' => 'PM-'.strtoupper(Str::random(10)),
                'buyer_id' => auth()->id(),
                'guest_email' => auth()->check() ? null : ($data['email'] ?? null),
                'guest_name' => auth()->check() ? null : ($address['name'] ?? null),
                'status' => OrderStatus::Placed,
                'subtotal' => $subtotal,
                'shipping_total' => $shippingTotal,
                'tax_total' => $tax->total,
                'shipping_tax' => $tax->shipping,
                'tax_calculation_id' => $tax->calculationId,
                'discount_total' => $discountTotal,
                'credits_used' => $creditsToUse,
                'coupon_id' => $appliedCoupon?->id,
                'total' => $total,
                'currency' => 'USD',
                'shipping_address' => $address,
                'billing_address' => $address,
            ]);

            foreach ($lines as $i => $line) {
                $product = $line['product'];
                $rate = $product->seller?->sellerProfile?->commissionRate()
                    ?? (float) config('marketplace.default_commission_rate');
                // What the buyer actually pays for this line after the seller's own discount.
                $lineDiscount = (float) ($lineDiscounts[$i] ?? 0);
                $netLine = round((float) $line['line_total'] - $lineDiscount, 2);
                $split = OrderItem::calculateCommission($netLine, $rate);

                $order->items()->create([
                    'seller_id' => $product->seller_id,
                    'product_id' => $product->id,
                    'product_variant_id' => $line['variant']?->id,
                    'product_title' => $product->title,
                    'variant_label' => $line['variant']?->label,
                    'product_sku' => $line['variant']?->sku ?: $product->sku,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'discount_amount' => $lineDiscount,
                    'line_total' => $netLine,
                    'tax_amount' => $tax->byLine[$i] ?? 0,
                    'commission_rate' => $rate,
                    'platform_fee_amount' => $split['platform_fee_amount'],
                    'seller_payout_amount' => $split['seller_payout_amount'],
                    'status' => OrderStatus::Placed,
                ]);
            }

            // Take the ordered quantity out of stock now, so the next buyer can't oversell it.
            app(\App\Services\Orders\OrderStockService::class)->commit($order);

            if ($appliedCoupon && auth()->check()) {
                // Record only what the coupon itself took off — not bundle savings too.
                $coupons->redeem($appliedCoupon, auth()->user(), $order, $couponDiscount);
            }
            if ($creditsToUse > 0) {
                $wallet->debit(auth()->user(), $creditsToUse, "Applied to order {$order->order_number}", $order);
            }

            return $order;
        });

        $cart->clear();
        session()->forget('checkout.coupon_code');

        // Campaign / promo-link conversion attribution.
        $this->attributeMarketing($request, $order);

        // Offsite Ads: charge the ad fee to any seller whose ad brought this buyer in.
        app(\App\Services\Marketing\OffsiteAdsService::class)
            ->attribute($order, $request->cookie(\App\Services\Marketing\OffsiteAdsService::COOKIE));

        $webhooks = app(\App\Services\Integrations\WebhookDispatcher::class);
        foreach ($order->items->groupBy('seller_id') as $sellerId => $sellerItems) {
            $webhooks->fire('order.created', (int) $sellerId, [
                'order_number' => $order->order_number,
                'items' => $sellerItems->map(fn ($i) => [
                    'order_item_id' => $i->id,
                    'product_id' => $i->product_id,
                    'title' => $i->product_title,
                    'variant' => $i->variant_label,
                    'quantity' => $i->quantity,
                    'line_total' => (float) $i->line_total,
                ])->all(),
                'ship_to' => $order->shipping_address,
                'placed_at' => now()->toIso8601String(),
            ]);
        }

        if ($order->total <= 0) {
            $order->update(['status' => OrderStatus::Paid, 'placed_at' => now()]);
            $order->items()->update(['status' => OrderStatus::Paid]);
            app(\App\Services\Integrations\WebhookDispatcher::class)->orderEvent('order.paid', $order->items()->with('order')->get());
            $salesTax->recordSale($order);

            if ($order->buyer) {
                app(ReferralService::class)->completeIfEligible($order->buyer);
            }
            app(\App\Services\Orders\OrderPaidNotifier::class)->notify($order);

            return redirect()->route('checkout.confirmation', $order->order_number)
                ->with('success', 'Order placed — fully covered by your credits.');
        }

        $paymentIntent = $payments->createPaymentIntent($order);
        $order->update(['stripe_payment_intent_id' => $paymentIntent->id]);

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

    private function attributeMarketing(Request $request, Order $order): void
    {
        $order->loadMissing('items');
        $sellerTotals = $order->items->groupBy('seller_id')->map->sum('line_total');

        if ($token = $request->cookie('pm_campaign')) {
            $recipient = \App\Models\CampaignRecipient::with('campaign')->where('token', $token)->first();
            $sellerId = $recipient?->campaign?->seller_id;
            if ($recipient && ! $recipient->converted_order_id && $sellerId && $sellerTotals->has($sellerId)) {
                $recipient->update([
                    'converted_order_id' => $order->id,
                    'conversion_value' => round($sellerTotals[$sellerId], 2),
                ]);
                \App\Models\MarketingEvent::conversion($sellerId, 'email', $recipient->marketing_campaign_id, $order->id, $sellerTotals[$sellerId]);
                app(\App\Services\Marketing\CampaignSender::class)->refreshStats($recipient->campaign);
            }
        }

        if ($code = $request->cookie('pm_ref')) {
            $link = \App\Models\PromoLink::where('code', $code)->first();
            if ($link && ($link->seller_id && $sellerTotals->has($link->seller_id))) {
                $link->increment('orders');
                $link->increment('revenue', round($sellerTotals[$link->seller_id], 2));
                \App\Models\MarketingEvent::conversion($link->seller_id, $link->channel(), $link->id, $order->id, $sellerTotals[$link->seller_id]);
            }
        }

        if ($ad = $request->cookie('pm_ad')) {
            [$source, $refId] = array_pad(explode(':', $ad, 2), 2, null);
            if ($source && $refId) {
                // Only the advertised seller's items count — not the whole basket or shipping.
                $ads = app(\App\Services\Seller\AdServingService::class);
                $adSeller = $ads->sellerFor($source, (int) $refId);
                if ($adSeller && $sellerTotals->has($adSeller)) {
                    $ads->attributeConversion((int) $refId, $source, round($sellerTotals[$adSeller], 2));
                    \App\Models\MarketingEvent::conversion($adSeller, 'ads', (int) $refId, $order->id, $sellerTotals[$adSeller]);
                }
            }
        }

        if ($affCode = $request->cookie('pm_aff')) {
            $conversion = app(\App\Services\Marketing\AffiliateService::class)->attribute($order, $affCode);
            if ($conversion?->wasRecentlyCreated) {
                \App\Models\MarketingEvent::conversion($conversion->affiliate->program->seller_id, 'affiliate', $conversion->affiliate_id, $order->id, (float) $conversion->order_value);
            }
        }

        if ($autoToken = $request->cookie('pm_automation')) {
            $send = \App\Models\AutomationSend::with('automation')->where('token', $autoToken)->first();
            $sellerId = $send?->automation?->seller_id;
            if ($send && ! $send->converted_order_id && $sellerId && $sellerTotals->has($sellerId)) {
                $send->update([
                    'converted_order_id' => $order->id,
                    'conversion_value' => round($sellerTotals[$sellerId], 2),
                ]);
                \App\Models\MarketingEvent::conversion($sellerId, 'automation', $send->marketing_automation_id, $order->id, $sellerTotals[$sellerId]);
                app(\App\Services\Marketing\MarketingAutomationService::class)->refreshStats($send->automation);
            }
        }
    }

    private function groupBySeller($lines)
    {
        return $lines->groupBy(fn ($l) => $l['product']->seller_id)
            ->map(fn ($group) => [
                'seller' => $group->first()['product']->seller,
                'lines' => $group,
                'subtotal' => $group->sum('line_total'),
            ]);
    }
}
