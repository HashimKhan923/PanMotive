@props(['price', 'sale' => null, 'size' => 'text-xl', 'badge' => true, 'basis' => null])
@php
    $has = $sale !== null && (float) $sale > 0 && (float) $sale < (float) $price;
    $now = $has ? $sale : $price;
    $off = $has ? round((1 - $sale / $price) * 100) : 0;
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex flex-wrap items-baseline gap-x-2 tabular-nums']) }}>
    <span class="{{ $size }} font-bold text-fg">${{ number_format((float) $now, 2) }}</span>
    @if ($basis)<span class="text-xs text-muted">{{ $basis }}</span>@endif
    @if ($has)
        <span class="text-[13px] text-muted line-through">${{ number_format((float) $price, 2) }}</span>
        @if ($badge)
            <span class="rounded bg-accent px-1.5 py-0.5 text-[11px] font-bold text-accent-fg">-{{ $off }}%</span>
        @endif
    @endif
</span>
