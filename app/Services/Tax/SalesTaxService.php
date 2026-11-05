<?php

namespace App\Services\Tax;

use App\Models\AdminAlert;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Shipping\ShippingEstimateService;

/**
 * US sales tax through Stripe Tax. Panmotive is the marketplace facilitator, so the tax is charged on
 * the order and kept by the platform to pay to the states: it never goes into a seller's payout.
 *
 * Which states are charged is decided by the tax registrations added in the Stripe dashboard. Stripe
 * returns no tax for a state with no registration, so there is no list of states to maintain here.
 *
 * Switched on with SALES_TAX_COLLECTING. While it's on, an order whose tax can't be worked out is
 * stopped rather than sold without tax, because the platform would owe that tax itself.
 */
class SalesTaxService
{
    public function __construct(private StripeTaxClient $client) {}

    public function enabled(): bool
    {
        return (bool) config('marketplace.sales_tax.collecting');
    }

    /** Switched on, and with the Stripe key it needs to actually work. */
    public function ready(): bool
    {
        return $this->enabled() && filled(config('services.stripe.secret'));
    }

    /**
     * What each line really sells for once the order-wide discount is shared out, because tax is
     * charged on the price paid. The last line takes the rounding remainder.
     *
     * @param  array<int|string, float>  $netLines  line totals after each seller's own discount
     * @return array<int|string, float>
     */
    public function taxableAmounts(array $netLines, float $platformDiscount): array
    {
        $sum = array_sum($netLines);
        if ($sum <= 0) {
            return array_map(fn () => 0.0, $netLines);
        }

        $target = round(max(0, $sum - $platformDiscount), 2);
        $remaining = $target;
        $keys = array_keys($netLines);
        $amounts = [];

        foreach ($keys as $position => $key) {
            $share = $position === count($keys) - 1
                ? $remaining
                : round($netLines[$key] * $target / $sum, 2);
            $amounts[$key] = max(0.0, $share);
            $remaining = round($remaining - $share, 2);
        }

        return $amounts;
    }

    /**
     * @param  array<int|string, float>  $amounts  taxable amount per line
     *
     * @throws SalesTaxUnavailable
     */
    public function calculate(array $amounts, float $shipping, array $address): TaxQuote
    {
        if (! $this->enabled()) {
            return new TaxQuote;
        }

        $lines = [];
        foreach ($amounts as $key => $amount) {
            if ($amount > 0) {
                $lines[] = [
                    'amount' => $this->cents($amount),
                    'reference' => "line-{$key}",
                    'tax_behavior' => 'exclusive',
                    'tax_code' => config('marketplace.sales_tax.product_tax_code'),
                ];
            }
        }

        if (! $lines && $shipping <= 0) {
            return new TaxQuote;
        }

        if (blank($address['postal_code'] ?? null) || blank($address['country'] ?? null)) {
            throw new SalesTaxUnavailable('The address has no ZIP code or country.');
        }

        // Stripe needs at least one line, and a fully discounted order can still owe tax on its
        // shipping. So in that case the shipping itself is sent as the line.
        $shippingOnly = ! $lines;
        if ($shippingOnly) {
            $lines[] = [
                'amount' => $this->cents($shipping),
                'reference' => 'shipping',
                'tax_behavior' => 'exclusive',
                'tax_code' => config('marketplace.sales_tax.shipping_tax_code'),
            ];
        }

        $params = [
            'currency' => 'usd',
            'line_items' => $lines,
            'customer_details' => [
                'address' => array_filter([
                    'line1' => $address['line1'] ?? null,
                    'line2' => $address['line2'] ?? null,
                    'city' => $address['city'] ?? null,
                    'state' => ShippingEstimateService::normalizeState($address['state'] ?? null),
                    'postal_code' => $address['postal_code'],
                    'country' => strtoupper($address['country']),
                ]),
                'address_source' => 'shipping',
            ],
        ];

        if ($shipping > 0 && ! $shippingOnly) {
            $params['shipping_cost'] = [
                'amount' => $this->cents($shipping),
                'tax_behavior' => 'exclusive',
                'tax_code' => config('marketplace.sales_tax.shipping_tax_code'),
            ];
        }

        try {
            $result = $this->client->calculate($params);
        } catch (\Throwable $e) {
            report($e);

            throw new SalesTaxUnavailable($e->getMessage(), 0, $e);
        }

        $byLine = [];
        foreach (array_keys($amounts) as $key) {
            $byLine[$key] = ($result['lines']["line-{$key}"] ?? 0) / 100;
        }

        $shippingTax = $shippingOnly ? $result['tax'] : $result['shipping_tax'];

        return new TaxQuote($result['tax'] / 100, $shippingTax / 100, $byLine, $result['id']);
    }

    /**
     * Once an order is paid, record the sale with Stripe so it appears in the tax reports. The buyer
     * has already paid, so a failure here raises an alert for staff instead of stopping anything.
     */
    public function recordSale(Order $order): void
    {
        if (! $order->tax_calculation_id || $order->tax_transaction_id) {
            return;
        }

        try {
            $order->update(['tax_transaction_id' => $this->client->record($order->tax_calculation_id, $order->order_number)]);
        } catch (\Throwable $e) {
            report($e);
            AdminAlert::create([
                'type' => 'sales_tax_not_recorded',
                'message' => "Order {$order->order_number} was paid, but Stripe Tax didn't record the sale: {$e->getMessage()}. Its \$".number_format((float) $order->tax_total, 2).' of tax is missing from the Stripe tax reports until this is fixed.',
            ]);
        }
    }

    /** Take a refund off the recorded sale. The money has already gone back, so a failure only raises an alert. */
    public function recordRefund(Order $order, OrderItem $item, float $amount): void
    {
        if (! $order->tax_transaction_id || $amount <= 0) {
            return;
        }

        try {
            $this->client->reverse($order->tax_transaction_id, "{$order->order_number}-refund-{$item->id}", $this->cents($amount));
        } catch (\Throwable $e) {
            report($e);
            AdminAlert::create([
                'type' => 'sales_tax_refund_not_recorded',
                'message' => "A \$".number_format($amount, 2)." refund on order {$order->order_number} went through, but Stripe Tax didn't record it: {$e->getMessage()}. The tax reports will show too much tax until this is fixed.",
                'related_order_item_id' => $item->id,
            ]);
        }
    }

    private function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
