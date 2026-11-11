<?php

namespace App\Services\Subscriptions;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SubscriptionOrderPlaced;
use App\Services\Marketing\WalletService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubscriptionService
{
    public function __construct(private WalletService $wallet) {}

    public const MIN_DISCOUNT = 5.0;
    public const MAX_DISCOUNT = 15.0;

    public function create(User $buyer, Product $product, array $opts = []): Subscription
    {
        $interval = (int) ($opts['interval_days'] ?? 30);
        abort_unless(array_key_exists($interval, Subscription::INTERVALS), 422);

        return Subscription::create([
            'buyer_id' => $buyer->id,
            'seller_id' => $product->seller_id,
            'product_id' => $product->id,
            'product_variant_id' => $opts['product_variant_id'] ?? null,
            'quantity' => max(1, (int) ($opts['quantity'] ?? 1)),
            'interval_days' => $interval,
            'discount_pct' => min(self::MAX_DISCOUNT, max(self::MIN_DISCOUNT, (float) ($opts['discount_pct'] ?? self::MIN_DISCOUNT))),
            'status' => 'active',
            'next_run_at' => $opts['starts_now'] ?? false ? now() : now()->addDays($interval),
            'shipping_address' => $opts['shipping_address'] ?? null,
        ]);
    }

    public function pause(Subscription $s): void
    {
        $s->update(['status' => 'paused']);
    }

    public function resume(Subscription $s): void
    {
        $s->update([
            'status' => 'active',
            'next_run_at' => $s->next_run_at?->isPast() ? now()->addDays($s->interval_days) : $s->next_run_at,
        ]);
    }

    public function cancel(Subscription $s): void
    {
        $s->update(['status' => 'cancelled']);
    }

    public function skipNext(Subscription $s): void
    {
        $s->update(['next_run_at' => ($s->next_run_at ?? now())->copy()->addDays($s->interval_days)]);
    }

    public function setSchedule(Subscription $s, int $intervalDays): void
    {
        abort_unless(array_key_exists($intervalDays, Subscription::INTERVALS), 422);
        $s->update([
            'interval_days' => $intervalDays,
            'next_run_at' => now()->addDays($intervalDays),
        ]);
    }

    /** Generate orders for every due subscription. Returns the count placed. */
    public function runDue(): int
    {
        $placed = 0;

        Subscription::where('status', 'active')
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->with(['product', 'variant', 'buyer'])
            ->chunkById(100, function ($subs) use (&$placed) {
                foreach ($subs as $sub) {
                    if ($this->placeOrder($sub)) {
                        $placed++;
                    }
                }
            });

        return $placed;
    }

    public function placeOrder(Subscription $sub): ?Order
    {
        $product = $sub->product;
        if (! $product || ! $product->isListed() || ! $sub->buyer || $sub->buyer->is_active === false) {
            // Skip this cycle but keep the subscription alive.
            $sub->update(['next_run_at' => now()->addDays($sub->interval_days)]);

            return null;
        }

        $unit = $sub->unitPrice();
        $lineTotal = round($unit * $sub->quantity, 2);
        $address = $sub->shipping_address ?: ($sub->buyer->default_address ?? ['line1' => 'On file']);

        $rate = $product->seller?->sellerProfile?->commissionRate()
            ?? (float) config('marketplace.default_commission_rate');
        $split = OrderItem::calculateCommission($lineTotal, $rate);

        $shipping = app(\App\Services\Shipping\ShippingEstimateService::class)
            ->estimate([['product' => $product, 'quantity' => $sub->quantity, 'line_total' => $lineTotal]], $sub->buyer)['shipping'];

        $salesTax = app(\App\Services\Tax\SalesTaxService::class);
        try {
            $tax = $salesTax->calculate([0 => $lineTotal], $shipping, $address);
        } catch (\App\Services\Tax\SalesTaxUnavailable $e) {
            // Don't place an order with the wrong total. Try again tomorrow, and tell staff.
            \App\Models\AdminAlert::create([
                'type' => 'subscription_tax_failed',
                'message' => "Subscription #{$sub->id} didn't place its order because the sales tax couldn't be worked out: {$e->getMessage()}",
            ]);
            $sub->update(['next_run_at' => now()->addDay()]);

            return null;
        }

        $order = DB::transaction(function () use ($sub, $product, $unit, $lineTotal, $address, $rate, $split, $shipping, $tax) {
            $total = round($lineTotal + $shipping + $tax->total, 2);

            $order = Order::create([
                'order_number' => 'PM-'.strtoupper(Str::random(10)),
                'buyer_id' => $sub->buyer_id,
                'status' => OrderStatus::Placed,
                'subtotal' => $lineTotal,
                'shipping_total' => $shipping,
                'tax_total' => $tax->total,
                'shipping_tax' => $tax->shipping,
                'tax_calculation_id' => $tax->calculationId,
                'discount_total' => 0,
                'credits_used' => 0,
                'subscription_id' => $sub->id,
                'total' => $total,
                'currency' => 'USD',
                'shipping_address' => $address,
                'billing_address' => $address,
            ]);

            $order->items()->create([
                'seller_id' => $product->seller_id,
                'product_id' => $product->id,
                'product_variant_id' => $sub->product_variant_id,
                'product_title' => $product->title,
                'variant_label' => $sub->variant?->label,
                'quantity' => $sub->quantity,
                'unit_price' => $unit,
                'line_total' => $lineTotal,
                'tax_amount' => $tax->byLine[0] ?? 0,
                'commission_rate' => $rate,
                'platform_fee_amount' => $split['platform_fee_amount'],
                'seller_payout_amount' => $split['seller_payout_amount'],
                'status' => OrderStatus::Placed,
            ]);

            app(\App\Services\Orders\OrderStockService::class)->commit($order);

            // Auto-pay from wallet credit when it fully covers the order.
            if ((float) $sub->buyer->wallet_balance >= $total) {
                $this->wallet->debit($sub->buyer, $total, "Subscription order {$order->order_number}", $order);
                $order->update(['status' => OrderStatus::Paid, 'placed_at' => now()]);
                $order->items()->update(['status' => OrderStatus::Paid]);
                app(\App\Services\Integrations\WebhookDispatcher::class)->orderEvent('order.paid', $order->items()->with('order')->get());
            }

            $sub->update([
                'last_order_id' => $order->id,
                'orders_count' => $sub->orders_count + 1,
                'next_run_at' => now()->addDays($sub->interval_days),
            ]);

            return $order;
        });

        if ($order->status === OrderStatus::Paid) {
            $salesTax->recordSale($order); // paid from wallet credit above; card orders record on payment
            // Receipt + the sellers' new-order alert (card-paid repeats go through the Stripe webhook).
            app(\App\Services\Orders\OrderPaidNotifier::class)->notify($order);
        }

        $sub->buyer->notify(new SubscriptionOrderPlaced($order, $sub));

        return $order;
    }
}
