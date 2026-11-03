@extends('layouts.print')
@section('title', 'Pick list')

@section('content')
<div class="flex items-center justify-between">
    <p class="text-lg font-bold">Pick list</p>
    <p class="text-xs text-slate-500">{{ now()->format('M j, Y g:i A') }} · {{ auth()->user()->sellerProfile->business_name }}</p>
</div>

@php
    $totals = collect($orders)->flatten(1)
        ->groupBy(fn ($i) => $i->product_id.'|'.($i->variant_label ?? ''))
        ->map(fn ($rows) => ['title' => $rows->first()->product_title, 'variant' => $rows->first()->variant_label, 'sku' => $rows->first()->product?->sku ?: $rows->first()->product?->part_number, 'qty' => $rows->sum('quantity')]);
@endphp

<p class="mt-4 text-xs font-semibold uppercase text-slate-400">Pull totals</p>
<table class="mt-1 w-full text-sm">
    <tbody>
        @foreach ($totals as $t)
            <tr class="border-b border-slate-100">
                <td class="py-1.5">{{ $t['title'] }}@if ($t['variant']) — {{ $t['variant'] }}@endif</td>
                <td class="py-1.5 text-slate-500">{{ $t['sku'] ?: '—' }}</td>
                <td class="py-1.5 text-right font-bold">×{{ $t['qty'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<p class="mt-6 text-xs font-semibold uppercase text-slate-400">By order ({{ count($orders) }})</p>
@forelse ($orders as $orderId => $items)
    @php($o = $items->first()->order)
    <div class="mt-2 rounded-lg border border-slate-200 p-3">
        <div class="flex justify-between text-sm">
            <span class="font-semibold">{{ $o->order_number }}</span>
            <span class="text-slate-500">{{ ($o->shipping_address['city'] ?? '') }}, {{ $o->shipping_address['state'] ?? '' }}</span>
        </div>
        <ul class="mt-1 text-sm">
            @foreach ($items as $i)
                <li class="flex justify-between border-t border-slate-100 py-1">
                    <span>☐ {{ $i->product_title }}@if ($i->variant_label) — {{ $i->variant_label }}@endif</span>
                    <span class="font-semibold">×{{ $i->quantity }}</span>
                </li>
            @endforeach
        </ul>
    </div>
@empty
    <p class="mt-2 text-sm text-slate-500">Nothing accepted and waiting to ship. 🎉</p>
@endforelse
@endsection
