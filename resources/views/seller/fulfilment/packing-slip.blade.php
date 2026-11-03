@extends('layouts.print')
@section('title', 'Packing slip ' . $order->order_number)

@section('content')
@php($addr = $order->shipping_address ?? [])
<div class="flex items-start justify-between">
    <div>
        <p class="text-lg font-bold">{{ auth()->user()->sellerProfile->business_name }}</p>
        <p class="text-xs text-slate-500">via Pan Motive</p>
    </div>
    <div class="text-right">
        <p class="text-sm font-semibold">Packing slip</p>
        <p class="text-xs text-slate-500">{{ $order->order_number }}</p>
        <p class="text-xs text-slate-500">{{ optional($order->placed_at ?? $order->created_at)->format('M j, Y') }}</p>
    </div>
</div>

<div class="mt-6 grid grid-cols-2 gap-6 text-sm">
    <div>
        <p class="text-xs font-semibold uppercase text-slate-400">Ship to</p>
        <p class="mt-1 font-medium">{{ $addr['name'] ?? $order->contactName() }}</p>
        <p>{{ $addr['line1'] ?? '' }}</p>
        @if (!empty($addr['line2']))<p>{{ $addr['line2'] }}</p>@endif
        <p>{{ $addr['city'] ?? '' }}{{ !empty($addr['state']) ? ', '.$addr['state'] : '' }} {{ $addr['postal_code'] ?? '' }}</p>
        <p>{{ $addr['country'] ?? '' }}</p>
    </div>
</div>

<table class="mt-6 w-full text-sm">
    <thead>
        <tr class="border-b border-slate-200 text-left text-xs uppercase text-slate-400">
            <th class="py-2">Item</th><th class="py-2">SKU / Part #</th><th class="py-2 text-right">Qty</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($items as $item)
            <tr class="border-b border-slate-100">
                <td class="py-2">{{ $item->product_title }}@if ($item->variant_label) — {{ $item->variant_label }}@endif</td>
                <td class="py-2 text-slate-500">{{ $item->product_sku ?: ($item->product?->part_number ?: '—') }}</td>
                <td class="py-2 text-right font-semibold">{{ $item->quantity }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<p class="mt-8 text-xs text-slate-400">Thank you for your order. Questions? Message the seller through Pan Motive.</p>
@endsection
