<?php

namespace App\Http\Controllers\Api\Shop;

use App\Http\Controllers\Controller;
use App\Models\Bundle;
use App\Models\Order;
use App\Models\Product;
use App\Services\Cart\CartService;
use App\Services\Marketing\CouponService;
use App\Services\Orders\CheckoutProblem;
use App\Services\Orders\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The cart and checkout in the shopping app. Guests' carts live on the phone's device id; signing in
 * moves the cart into the account. Placing an order uses the same CheckoutService as the website, then
 * the app takes the card / Apple Pay / Google Pay payment with Stripe's payment sheet.
 */
class CartController extends Controller
{
    /** The cart with everything the cart and checkout screens show. Optional ?coupon= and ?state=. */
    public function show(Request $request, CartService $cart, CheckoutService $checkout): JsonResponse
    {
        $request->validate(['coupon' => 'nullable|string|max:64', 'state' => 'nullable|string|max:120']);

        return response()->json($this->summary($cart, $checkout, $request->input('coupon'), $request->input('state')));
    }

    public function add(Request $request, CartService $cart, CheckoutService $checkout): JsonResponse
    {
        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'variant_id' => 'nullable|integer|exists:product_variants,id',
            'quantity' => 'nullable|integer|min:1|max:99',
        ]);

        $product = Product::where('id', $data['product_id'])->listed()->firstOrFail();
        if ($product->seller?->sellerProfile?->salesPaused()) {
            return $this->problem('This seller is away and not taking orders right now.');
        }

        $variantId = null;
        if ($product->hasVariants()) {
            $variant = $product->activeVariants()->find($data['variant_id'] ?? null);
            if (! $variant) {
                return $this->problem('Please choose an option.');
            }
            if ((int) $variant->quantity < 1) {
                return $this->problem('Sorry — that option just sold out.');
            }
            $variantId = $variant->id;
        } elseif ((int) $product->quantity < 1) {
            return $this->problem('Sorry — that part just sold out.');
        }

        $cart->add($product->id, (int) ($data['quantity'] ?? 1), $variantId);

        return response()->json(['message' => 'Added to cart'] + $this->summary($cart, $checkout));
    }

    public function update(Request $request, CartService $cart, CheckoutService $checkout): JsonResponse
    {
        $data = $request->validate([
            'product_id' => 'required|integer',
            'variant_id' => 'nullable|integer',
            'quantity' => 'required|integer|min:0|max:99',
        ]);

        // Never more than what's in stock.
        $product = Product::find($data['product_id']);
        $max = $data['variant_id'] ?? null
            ? (int) ($product?->variants()->whereKey($data['variant_id'])->value('quantity') ?? 0)
            : (int) ($product?->quantity ?? 0);
        $cart->setQuantity($data['product_id'], min($data['quantity'], max(0, $max)), $data['variant_id'] ?? null);

        return response()->json($this->summary($cart, $checkout, $request->input('coupon'), $request->input('state')));
    }

    public function remove(Request $request, CartService $cart, CheckoutService $checkout): JsonResponse
    {
        $data = $request->validate(['product_id' => 'required|integer', 'variant_id' => 'nullable|integer']);
        $cart->remove($data['product_id'], $data['variant_id'] ?? null);

        return response()->json($this->summary($cart, $checkout, $request->input('coupon'), $request->input('state')));
    }

    /** Put every item of a seller's bundle in the cart so the bundle discount applies. */
    public function addBundle(Bundle $bundle, CartService $cart, CheckoutService $checkout): JsonResponse
    {
        $bundle->load('products');
        abort_unless($bundle->is_active && $bundle->isAvailable(), 404);
        if ($bundle->products->contains(fn ($p) => $p->hasVariants())) {
            return $this->problem('Some items in this bundle have options — add each one from its listing to choose.');
        }
        foreach ($bundle->products as $product) {
            $cart->add($product->id, (int) $product->pivot->quantity);
        }

        return response()->json([
            'message' => "\"{$bundle->title}\" added — you're saving \$".number_format($bundle->savings(), 2).'.',
        ] + $this->summary($cart, $checkout));
    }

    /** Check a coupon code before checkout (the app keeps the code and sends it with the order). */
    public function coupon(Request $request, CartService $cart, CouponService $coupons): JsonResponse
    {
        $request->validate(['code' => 'required|string|max:64']);
        $result = $coupons->validate((string) $request->code, $request->user(), $cart->subtotal(), $cart->lines());

        return $result['valid']
            ? response()->json(['valid' => true, 'code' => strtoupper((string) $request->code), 'discount' => round((float) $result['discount'], 2), 'message' => 'Coupon applied!'])
            : response()->json(['valid' => false, 'message' => $result['message']], 422);
    }

    /**
     * Place the order. Returns the order and, unless store credit covered it all, the Stripe payment
     * for the app's payment sheet. The order is paid once Stripe confirms (webhook) — the app then
     * polls GET /orders/{number}/status.
     */
    public function checkout(Request $request, CartService $cart, CheckoutService $checkout): JsonResponse
    {
        if (! config('marketplace.platform.checkout_enabled', true)) {
            return $this->problem('Checkout is paused for a short time. Your cart is saved.', 'checkout_paused', 503);
        }
        $user = $request->user();
        $data = $request->validate(
            CheckoutService::addressRules(guest: ! $user) + CheckoutService::billingRules() + ['coupon' => 'nullable|string|max:64', 'use_credits' => 'nullable|boolean'],
            CheckoutService::addressMessages() + CheckoutService::billingMessages(),
        );

        try {
            $order = $checkout->place(
                $cart, $user,
                collect($data)->only(['name', 'line1', 'line2', 'city', 'state', 'postal_code', 'country', 'phone', 'email'])->all(),
                $data['coupon'] ?? null,
                $user && (bool) ($data['use_credits'] ?? false),
                [],
                CheckoutService::billingFrom($data),
            );
        } catch (CheckoutProblem $problem) {
            return response()->json(['message' => $problem->getMessage(), 'errors' => [$problem->field => [$problem->getMessage()]]], 422);
        }

        $intent = $checkout->pay($order);

        return response()->json([
            'order' => [
                'number' => $order->order_number,
                'total' => round((float) $order->total, 2),
                'status' => $order->fresh()->status->value,
                'access' => $user ? null : self::guestAccess($order),
            ],
            'payment' => $intent ? [
                'client_secret' => $intent->client_secret,
                'publishable_key' => config('services.stripe.key'),
                'merchant_name' => config('app.name'),
                'amount' => (int) round($order->total * 100),
            ] : null,
        ], 201);
    }

    /** A guest's order can't be looked up by number alone: this signed key proves they placed it. */
    public static function guestAccess(Order $order): string
    {
        return hash_hmac('sha256', 'app-order:'.$order->order_number, (string) config('app.key'));
    }

    /** @return array<string, mixed> */
    private function summary(CartService $cart, CheckoutService $checkout, ?string $coupon = null, ?string $state = null): array
    {
        $q = $checkout->quote($cart, request()->user(), $coupon, $state);
        /** @var Collection $lines */
        $lines = $q['lines'];

        $groups = $lines->groupBy(fn ($l) => $l['product']->seller_id)->map(function ($group, $sellerId) use ($q) {
            $sp = $group->first()['product']->seller?->sellerProfile;

            return [
                'seller' => $sp ? ['name' => $sp->business_name, 'slug' => $sp->slug, 'logo' => $sp->logoUrl(), 'away' => $sp->salesPaused()] : null,
                'subtotal' => round($group->sum('line_total'), 2),
                'shipping' => round((float) ($q['shipping_per_seller'][$sellerId] ?? 0), 2),
                'lines' => $group->map(fn ($l) => [
                    'product_id' => $l['cart_product_id'] ?? $l['product']->id,
                    'variant_id' => $l['cart_variant_id'] ?? $l['variant']?->id,
                    'slug' => $l['product']->slug,
                    'title' => $l['product']->title,
                    'variant' => $l['variant']?->label,
                    'image' => $l['variant']?->imageUrl('thumb') ?: ($l['product']->getFirstMediaUrl('images', 'thumb') ?: null),
                    'unit_price' => round((float) $l['unit_price'], 2),
                    'list_unit_price' => round((float) ($l['list_unit_price'] ?? $l['unit_price']), 2),
                    'quantity' => (int) $l['quantity'],
                    'line_total' => round((float) $l['line_total'], 2),
                    'max' => (int) ($l['variant']?->quantity ?? $l['product']->quantity),
                ])->values(),
            ];
        })->values();

        return [
            'count' => (int) $lines->sum('quantity'),
            'groups' => $groups,
            'subtotal' => $q['subtotal'],
            'shipping' => round($q['shipping'], 2),
            'shipping_free' => $q['shipping_free'],
            'free_threshold' => $q['free_threshold'],
            'coupon_discount' => $q['coupon_discount'],
            'coupon_message' => $q['coupon_message'],
            'bundle_savings' => $q['bundle_savings'],
            'bundles' => $q['bundles'],
            'credits_available' => round((float) $q['credits_available'], 2),
            'blocked' => $q['blocked_titles'],
            'tax_note' => $q['tax_collecting'] ? 'Sales tax is added at checkout.' : null,
            'fit' => $q['fit'],
            'estimated_total' => $q['estimated_total'],
        ];
    }

    private function problem(string $message, string $code = 'cart', int $status = 422): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }
}
