@props(['price', 'sale' => null, 'size' => 'text-xl'])
@php
    $has = $sale !== null && (float) $sale > 0 && (float) $sale < (float) $price;
    $now = $has ? $sale : $price;
    $off = $has ? round((1 - $sale / $price) * 100) : 0;
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-baseline gap-2']) }}>
    <span class="{{ $size }} font-bold text-fg">${{ number_format((float) $now, 2) }}</span>
    @if ($has)
        <span class="text-sm text-muted line-through">${{ number_format((float) $price, 2) }}</span>
        <span class="rounded bg-accent/15 px-1.5 py-0.5 text-[11px] font-bold text-accent">-{{ $off }}%</span>
    @endif
</span>
