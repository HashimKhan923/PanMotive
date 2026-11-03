@props([
    'value' => 0,        // 0–100 (drives the arc)
    'display' => null,    // number shown in the middle (defaults to value)
    'suffix' => '%',
    'label' => null,
    'sub' => null,
    'size' => 128,
    'stroke' => 10,
    'tone' => 'accent',  // accent | emerald | amber | rose
])
@php
    $v = max(0, min(100, (float) $value));
    $shown = $display ?? round($v);
    $r = ($size - $stroke) / 2;
    $circ = 2 * M_PI * $r;
    $color = ['accent' => 'text-accent', 'emerald' => 'text-emerald-500', 'amber' => 'text-amber-500', 'rose' => 'text-rose-500'][$tone] ?? 'text-accent';
@endphp

<div class="inline-flex flex-col items-center"
     x-data="{ o: {{ $circ }}, n: 0 }"
     x-init="$nextTick(() => {
        setTimeout(() => o = {{ $circ * (1 - $v / 100) }}, 120);
        let start = Date.now();
        let id = setInterval(() => {
            let p = Math.min(1, (Date.now() - start) / 1000);
            n = ({{ $shown }}) * (1 - Math.pow(1 - p, 3));
            if (p >= 1) clearInterval(id);
        }, 16);
     })">
    <div class="relative" style="width: {{ $size }}px; height: {{ $size }}px;">
        <svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 {{ $size }} {{ $size }}" class="-rotate-90">
            <circle cx="{{ $size / 2 }}" cy="{{ $size / 2 }}" r="{{ $r }}" fill="none" stroke="currentColor" stroke-width="{{ $stroke }}" class="text-line/50" />
            <circle cx="{{ $size / 2 }}" cy="{{ $size / 2 }}" r="{{ $r }}" fill="none" stroke="currentColor" stroke-width="{{ $stroke }}" stroke-linecap="round"
                    class="{{ $color }}" stroke-dasharray="{{ $circ }}" :stroke-dashoffset="o"
                    style="transition: stroke-dashoffset 1s cubic-bezier(.22,1,.36,1);" />
        </svg>
        <div class="absolute inset-0 flex items-center justify-center">
            <span class="text-xl font-bold" x-text="(Number.isInteger({{ $shown }}) ? Math.round(n) : n.toFixed(1)) + '{{ $suffix }}'">{{ $shown }}{{ $suffix }}</span>
        </div>
    </div>
    @if ($label)<span class="mt-2 text-sm font-medium">{{ $label }}</span>@endif
    @if ($sub)<span class="text-xs text-muted">{{ $sub }}</span>@endif
</div>
