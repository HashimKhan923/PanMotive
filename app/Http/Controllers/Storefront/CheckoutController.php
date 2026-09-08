<?php

namespace App\Http\Controllers\Storefront;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Payments\CheckoutPaymentService;
use App\Support\Cart;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
    public function index(Cart $cart)
    {
        $lines = $this->resolveLines($cart);

        return view('storefront.checkout.index', [
            'lines' => $lines,
            'subtotal' => $lines->sum('line_total'),
        ]);
    }

    public function store(Request $request, Cart $cart, CheckoutPaymentService $payments)
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

        $lines = $this->resolveLines($cart);

        if ($lines->isEmpty()) {
            return redirect()->route('cart.index')->with('status', 'None of your cart items are available.');
        }

        $order = DB::transaction(function () use ($lines, $address) {
            $subtotal = $lines->sum('line_total');

            $order = Order::create([
                'order_number' => 'PM-'.strtoupper(Str::random(10)),
                'buyer_id' => auth()->id(),
                'status' => OrderStatus::Placed,
                'subtotal' => $subtotal,
                'shipping_total' => 0,
                'tax_total' => 0,
                'discount_total' => 0,
                'total' => $subtotal,
                'currency' => 'USD',
                'shipping_address' => $address,
                'billing_address' => $address,
            ]);

            foreach ($lines as $line) {
                /** @var Product $product */
                $product = $line['product'];
                $rate = $product->seller?->sellerProfile?->commissionRate()
                    ?? (float) config('marketplace.default_commission_rate');

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

            return $order;
        });

        $paymentIntent = $payments->createPaymentIntent($order);
        $order->update(['stripe_payment_intent_id' => $paymentIntent->id]);

        $cart->clear();

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

    /**
     * @return \Illuminate\Support\Collection<int, array{product: Product, quantity: int, unit_price: float, line_total: float}>
     */
    private function resolveLines(Cart $cart): \Illuminate\Support\Collection
    {
        $raw = collect($cart->items());

        $products = Product::query()
            ->with('seller.sellerProfile')
            ->whereIn('id', $raw->pluck('product_id'))
            ->where('status', 'active')
            ->get()
            ->keyBy('id');

        return $raw
            ->filter(fn ($item) => $products->has($item['product_id']))
            ->map(function ($item) use ($products) {
                $product = $products[$item['product_id']];
                $unit = (float) ($product->sale_price ?? $product->price);

                return [
                    'product' => $product,
                    'quantity' => $item['quantity'],
                    'unit_price' => $unit,
                    'line_total' => round($unit * $item['quantity'], 2),
                ];
            })
            ->values();
    }
}
