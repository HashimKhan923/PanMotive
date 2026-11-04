@extends('layouts.print')
@section('title', 'Invoice ' . $order->order_number)

@section('content')
@php
    $addr = $order->shipping_address ?? [];
    $bill = $order->billing_address ?: $addr;
    $live = $items->reject(fn ($i) => in_array($i->status->value, ['cancelled', 'refunded']));
    $subtotal = (float) $live->sum('line_total');
@endphp
<div class="flex items-start justify-between">
    <div>
        <p class="text-lg font-bold">{{ $store?->business_name }}</p>
        <p class="text-xs text-slate-500">Sold via PanMotive</p>
    </div>
    <div class="text-right">
        <p class="text-xl font-bold">Invoice</p>
        <p class="text-xs text-slate-500">Order {{ $order->order_number }}</p>
        <p class="text-xs text-slate-500">Date {{ optional($order->placed_at ?? $order->created_at)->format('M j, Y') }}</p>
    </div>
</div>

<div class="mt-6 grid grid-cols-2 gap-6 text-sm">
    <div>
        <p class="text-xs font-semibold uppercase text-slate-400">Bill to</p>
        <p class="mt-1 font-medium">{{ $bill['name'] ?? $order->contactName() }}</p>
        <p>{{ $bill['line1'] ?? '' }}</p>
        @if (!empty($bill['line2']))<p>{{ $bill['line2'] }}</p>@endif
        <p>{{ $bill['city'] ?? '' }}{{ !empty($bill['state']) ? ', '.$bill['state'] : '' }} {{ $bill['postal_code'] ?? '' }}</p>
    </div>
    <div>
        <p class="text-xs font-semibold uppercase text-slate-400">Ship to</p>
        <p class="mt-1 font-medium">{{ $addr['name'] ?? $order->contactName() }}</p>
        <p>{{ $addr['line1'] ?? '' }}</p>
        @if (!empty($addr['line2']))<p>{{ $addr['line2'] }}</p>@endif
        <p>{{ $addr['city'] ?? '' }}{{ !empty($addr['state']) ? ', '.$addr['state'] : '' }} {{ $addr['postal_code'] ?? '' }}</p>
    </div>
</div>

<table class="mt-6 w-full text-sm">
    <thead>
        <tr class="border-b border-slate-200 text-left text-xs uppercase text-slate-400">
            <th class="py-2">Item</th><th class="py-2">SKU</th><th class="py-2 text-right">Qty</th>
            <th class="py-2 text-right">Unit price</th><th class="py-2 text-right">Amount</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($items as $item)
            @php($void = in_array($item->status->value, ['cancelled', 'refunded']))
            <tr class="border-b border-slate-100 {{ $void ? 'text-slate-400' : '' }}">
                <td class="py-2">
                    {{ $item->product_title }}@if ($item->variant_label) — {{ $item->variant_label }}@endif
                    @if ($void)<span class="ml-1 text-xs uppercase">({{ $item->status->label() }})</span>@endif
                </td>
                <td class="py-2">{{ $item->product_sku ?: '—' }}</td>
                <td class="py-2 text-right">{{ $item->quantity }}</td>
                <td class="py-2 text-right">${{ number_format($item->unit_price, 2) }}</td>
                <td class="py-2 text-right font-semibold {{ $void ? 'line-through' : '' }}">${{ number_format($item->line_total, 2) }}</td>
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr><td colspan="4" class="pt-3 text-right text-slate-500">Subtotal</td><td class="pt-3 text-right font-semibold">${{ number_format($subtotal, 2) }}</td></tr>
        <tr><td colspan="4" class="text-right text-slate-500">Sales tax</td><td class="text-right">${{ number_format(0, 2) }}</td></tr>
        <tr class="text-base"><td colspan="4" class="pt-2 text-right font-bold">Total</td><td class="pt-2 text-right font-bold">${{ number_format($subtotal, 2) }}</td></tr>
    </tfoot>
</table>

<p class="mt-8 text-xs text-slate-500">
    Shipping and any order-wide discounts are settled by PanMotive on the buyer's combined receipt.
    Questions about this order? Message {{ $store?->business_name }} from your PanMotive account.
</p>
@endsection
