<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SellerBlockedBuyer;
use App\Models\User;
use App\Services\Cart\CartService;
use App\Services\FitGuarantee;
use App\Services\Integrations\WebhookDispatcher;
use App\Services\Marketing\CouponService;
use App\Services\Marketing\ReferralService;
use App\Services\Marketing\WalletService;
use App\Services\Payments\CheckoutPaymentService;
use App\Services\Shipping\ShippingEstimateService;
use App\Services\Tax\SalesTaxService;
use App\Services\Tax\SalesTaxUnavailable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Stripe\PaymentIntent;

/**
 * Placing an order — one set of rules for the website's checkout and the shopping app.
 * Works out prices, shipping, coupons, bundles, store credit and sales tax, checks the seller can
 * sell to this buyer at this address, then saves the order and takes the stock.
 */
class CheckoutService
{
    public function __construct(
        private CouponService $coupons,
        private WalletService $wallet,
        private ShippingEstimateService $shipping,
        private SalesTaxService $salesTax,
        private CheckoutPaymentService $payments,
    ) {}

    /** Validation rules for the delivery address (and email for guests). */
    public static function addressRules(bool $guest): array
    {
        $rules = [
            'name' => 'required|string|max:255',
            'line1' => 'required|string|max:255',
            'line2' => 'nullable|string|max:255',
            'city' => 'required|string|max:120',
            'state' => 'required|string|max:120',
            'postal_code' => 'required|string|max:20',
            'country' => 'required|string|size:2',
            'phone' => ['required', 'string', 'max:30', function ($attr, $value, $fail) {
                $digits = strlen(preg_replace('/\D/', '', (string) $value));
                if ($digits < 10 || $digits > 15 || preg_match('/[^0-9+()\-.\s]/', (string) $value)) {
                    $fail('Enter a valid phone number, like (555) 123-4567.');
                }
            }],
        ];
        if ($guest) {
            $rules['email'] = 'required|email|max:255';
        }

        return $rules;
    }

    public static function addressMessages(): array
    {
        return [
            'name.required' => 'Enter your full name.',
            'line1.required' => 'Enter your street address.',
            'city.required' => 'Enter your city.',
            'state.required' => 'Enter your state.',
            'postal_code.required' => 'Enter your ZIP code.',
            'phone.required' => 'Enter a phone number so the carrier can reach you about delivery.',
            'email.required' => 'Enter your email for the receipt and tracking.',
        ];
    }

    /**
     * The checkout summary before paying (cart, shipping, discounts, credit). Tax is only known once
     * the order is placed with an address, so it isn't included here.
     *
     * @return array<string, mixed>
     */
    public function quote(CartService $cart, ?User $user, ?string $couponCode, ?string $state = null): array
    {
        $lines = $cart->lines();
        $subtotal = (float) $lines->sum('line_total');

        $discount = 0.0;
        $couponMessage = null;
        if ($couponCode) {
            $result = $this->coupons->validate($couponCode, $user, $subtotal, $lines);
            $discount = $result['valid'] ? (float) $result['discount'] : 0.0;
            $couponMessage = $result['valid'] ? null : $result['message'];
        }

        $state = $state ? ShippingEstimateService::normalizeState($state) : null;
        $ship = $this->shipping->estimate($lines, $user, $state);
        $bundle = $cart->bundleDiscount($lines);
        $discountTotal = round($discount + $bundle['total'], 2);

        return [
            'lines' => $lines,
            'subtotal' => round($subtotal, 2),
            'shipping' => (float) $ship['shipping'],
            'shipping_free' => (bool) $ship['free'],
            'free_threshold' => (float) $ship['threshold'],
            'shipping_per_seller' => $ship['per_seller'],
            'coupon_discount' => round($discount, 2),
            'coupon_message' => $couponMessage,
            'bundle_savings' => (float) $bundle['total'],
            'bundles' => $bundle['applied'],
            'discount_total' => $discountTotal,
            'credits_available' => $user ? $this->wallet->maxRedeemable($user, max(0, $subtotal - $discountTotal)) : 0.0,
            'blocked_titles' => $state ? $this->shipping->restrictedTitles($lines, $state) : [],
            'tax_collecting' => $this->salesTax->enabled(),
            'fit' => app(FitGuarantee::class)->check($lines),
            'estimated_total' => round(max(0, $subtotal + (float) $ship['shipping'] - $discountTotal), 2),
        ];
    }

    /**
     * Save the order and take the stock. Card payment happens after (see pay()).
     *
     * @param  array<string, mixed>  $address  validated with addressRules(); 'email' for guests
     * @param  array<string, ?string>  $attribution  marketing cookies (website only)
     *
     * @throws CheckoutProblem when the order can't be placed as it is
     */
    public function place(CartService $cart, ?User $user, array $address, ?string $couponCode, bool $useCredits, array $attribution = []): Order
    {
        if ($cart->isEmpty()) {
            throw new CheckoutProblem('cart', 'Your cart is empty.');
        }

        $email = $address['email'] ?? null;
        $address = collect($address)->except('email')->all();
        $address['phone'] = self::cleanPhone((string) $address['phone']);

        // Remember the phone on the account for next time.
        if ($user && blank($user->phone)) {
            $user->forceFill(['phone' => $address['phone']])->save();
        }

        $lines = $cart->lines();
        if ($lines->isEmpty()) {
            throw new CheckoutProblem('cart', 'None of your cart items are available.');
        }

        // Store the state as its 2-letter code ("California" → "CA") so restrictions, surcharges,
        // labels and tax all see the same value.
        $address['state'] = ShippingEstimateService::normalizeState($address['state'] ?? null);

        // Sellers away with sales paused aren't taking new orders.
        $away = $lines->filter(fn ($l) => $l['product']->seller?->sellerProfile?->salesPaused())
            ->map(fn ($l) => $l['product']->title)->unique()->values();
        if ($away->isNotEmpty()) {
            throw new CheckoutProblem('name', 'The seller of these items is away and not taking orders right now: '.$away->implode(', ').'. Remove them from your cart or save them for later.');
        }

        // A seller who blocked this buyer won't sell to them.
        if ($user) {
            $refused = $lines->filter(fn ($l) => SellerBlockedBuyer::isBlocked($l['product']->seller_id, $user->id))
                ->map(fn ($l) => $l['product']->title)->unique()->values();
            if ($refused->isNotEmpty()) {
                throw new CheckoutProblem('name', "The seller of these items isn't accepting orders from your account: ".$refused->implode(', ').'. Remove them from your cart to continue.');
            }
        }

        // Sellers can refuse certain states (e.g. CARB-restricted parts to California, or AK/HI).
        if ($blocked = $this->shipping->restrictedTitles($lines, $address['state'])) {
            throw new CheckoutProblem('state', 'These items can\'t be shipped to '.$address['state'].': '.implode(', ', $blocked)
                .'. Remove them from your cart or use a different address.');
        }

        $subtotal = (float) $lines->sum('line_total');
        $shippingTotal = $this->shipping->estimate($lines, $user, $address['state'] ?? null)['shipping'];

        $appliedCoupon = null;
        $couponDiscount = 0.0;
        if ($couponCode) {
            $result = $this->coupons->validate($couponCode, $user, $subtotal, $lines);
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
        $lineDiscounts = app(SellerDiscountAllocator::class)->allocate($lines, $bundle['by_product'], $appliedCoupon, $couponDiscount);

        $creditsToUse = $user && $useCredits ? $this->wallet->maxRedeemable($user, max(0, $subtotal - $discountTotal)) : 0.0;

        // Tax is charged on what's actually paid for the goods: store credit is a way of paying, not a
        // discount, so it doesn't lower the tax. Worked out before anything is saved, so a problem
        // here leaves the cart untouched.
        $netLines = $lines->map(fn ($line, $i) => round((float) $line['line_total'] - (float) ($lineDiscounts[$i] ?? 0), 2))->all();
        $taxable = $this->salesTax->taxableAmounts($netLines, max(0, $discountTotal - (float) array_sum((array) $lineDiscounts)));
        try {
            $tax = $this->salesTax->calculate($taxable, $shippingTotal, $address);
        } catch (SalesTaxUnavailable) {
            throw new CheckoutProblem('postal_code', 'We couldn\'t work out the sales tax for this address. Check the ZIP code and state, then try again.');
        }

        $total = round(max(0, $subtotal + $shippingTotal + $tax->total - $discountTotal - $creditsToUse), 2);

        $order = DB::transaction(function () use ($lines, $lineDiscounts, $couponDiscount, $address, $email, $user, $subtotal, $shippingTotal, $discountTotal, $creditsToUse, $total, $tax, $appliedCoupon) {
            $order = Order::create([
                'order_number' => 'PM-'.strtoupper(Str::random(10)),
                'buyer_id' => $user?->id,
                'guest_email' => $user ? null : $email,
                'guest_name' => $user ? null : ($address['name'] ?? null),
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
                    // Fit Guarantee: which car this was bought for, and whether the listing covered it.
                    ...app(FitGuarantee::class)->snapshot($product),
                ]);
            }

            // Take the ordered quantity out of stock now, so the next buyer can't oversell it.
            app(OrderStockService::class)->commit($order);

            if ($appliedCoupon && $user) {
                // Record only what the coupon itself took off — not bundle savings too.
                $this->coupons->redeem($appliedCoupon, $user, $order, $couponDiscount);
            }
            if ($creditsToUse > 0) {
                $this->wallet->debit($user, $creditsToUse, "Applied to order {$order->order_number}", $order);
            }

            return $order;
        });

        $cart->clear();

        // Campaign / promo-link / ad / affiliate conversion attribution (website cookies).
        app(CheckoutAttribution::class)->attribute($order, $attribution);

        $webhooks = app(WebhookDispatcher::class);
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

        return $order;
    }

    /**
     * An order fully covered by store credit is paid straight away (null is returned); otherwise the
     * card payment to complete is returned.
     */
    public function pay(Order $order): ?PaymentIntent
    {
        if ($order->total <= 0) {
            $order->update(['status' => OrderStatus::Paid, 'placed_at' => now()]);
            $order->items()->update(['status' => OrderStatus::Paid]);
            app(WebhookDispatcher::class)->orderEvent('order.paid', $order->items()->with('order')->get());
            $this->salesTax->recordSale($order);

            if ($order->buyer) {
                app(ReferralService::class)->completeIfEligible($order->buyer);
            }
            app(OrderPaidNotifier::class)->notify($order);

            return null;
        }

        $intent = $this->payments->createPaymentIntent($order);
        $order->update(['stripe_payment_intent_id' => $intent->id]);

        return $intent;
    }

    /** "(555) 123-4567" → "+15551234567"; other countries keep their own digits with a "+". */
    public static function cleanPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }

        return '+'.$digits;
    }

    /** @param Collection<int, array<string, mixed>> $lines */
    public static function groupBySeller(Collection $lines): Collection
    {
        return $lines->groupBy(fn ($l) => $l['product']->seller_id)
            ->map(fn ($group) => [
                'seller' => $group->first()['product']->seller,
                'lines' => $group,
                'subtotal' => $group->sum('line_total'),
            ]);
    }
}
