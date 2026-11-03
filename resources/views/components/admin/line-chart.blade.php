{{-- One measure over time: a 2px line, this period against the last one in gray.
     Hover gives a crosshair and tooltip. The same numbers are in a hidden table for screen readers. --}}
@props([
    'labels' => [],      // x labels
    'values' => [],      // this period
    'previous' => null,  // same-length earlier period (optional)
    'prefix' => '',
    'decimals' => 0,
    'height' => 240,
    'name' => 'This period',
    'previousName' => 'Previous period',
])
@php
    $values = array_map('floatval', array_values($values));
    $previous = $previous ? array_map('floatval', array_values($previous)) : null;
    $n = count($values);
    $W = 800; $H = $height; $top = 12; $bottom = 26; $left = 54; $right = 12;

    $max = max([1, ...$values, ...($previous ?? [])]);
    // A readable top value: 1, 2, 2.5 or 5 × a power of ten.
    $mag = 10 ** floor(log10($max));
    foreach ([1, 2, 2.5, 5, 10] as $step) { if ($step * $mag >= $max) { $niceMax = $step * $mag; break; } }

    $x = fn ($i) => $left + ($n > 1 ? $i / ($n - 1) : .5) * ($W - $left - $right);
    $y = fn ($v) => $top + (1 - $v / $niceMax) * ($H - $top - $bottom);
    $path = fn ($set) => collect($set)->map(fn ($v, $i) => ($i ? 'L' : 'M').round($x($i), 1).' '.round($y($v), 1))->implode(' ');
    $money = fn ($v) => $prefix.number_format($v, $decimals);
    $tick = fn ($v) => $prefix.($v >= 1000 ? rtrim(rtrim(number_format($v / 1000, 1), '0'), '.').'k' : number_format($v, $v < 10 && $v != (int) $v ? 1 : 0));

    $points = collect($values)->map(fn ($v, $i) => [
        'x' => $x($i) / $W, 'y' => $y($v) / $H,
        'label' => $labels[$i] ?? '', 'value' => $money($v),
        'prev' => $previous ? $money($previous[$i] ?? 0) : null,
    ])->values();
    $every = max(1, (int) ceil($n / 7));
    $gradient = 'adm-grad-'.uniqid();
@endphp
<div {{ $attributes }}>
    @if ($previous)
        <div class="mb-2 flex items-center gap-4 text-xs text-muted">
            <span class="inline-flex items-center gap-1.5"><span class="h-0.5 w-4 rounded bg-accent"></span> {{ $name }}</span>
            <span class="inline-flex items-center gap-1.5"><span class="h-0.5 w-4 rounded bg-muted/60"></span> {{ $previousName }}</span>
        </div>
    @endif
    <div class="relative" x-data="admLineChart(@js($points))" x-ref="plot" @pointermove="hover($event)" @pointerleave="i = null">
        <svg viewBox="0 0 {{ $W }} {{ $H }}" class="block w-full" role="img" aria-label="{{ $name }} chart">
            <defs>
                <linearGradient id="{{ $gradient }}" x1="0" x2="0" y1="0" y2="1">
                    <stop offset="0" stop-color="rgb(var(--c-accent))" stop-opacity=".22" />
                    <stop offset="1" stop-color="rgb(var(--c-accent))" stop-opacity="0" />
                </linearGradient>
            </defs>
            @foreach ([0, .25, .5, .75, 1] as $f)
                <line x1="{{ $left }}" x2="{{ $W - $right }}" y1="{{ $y($niceMax * $f) }}" y2="{{ $y($niceMax * $f) }}" stroke="rgb(var(--c-line))" stroke-opacity=".5" stroke-width="1" @if ($f) stroke-dasharray="3 5" @endif />
                <text x="{{ $left - 8 }}" y="{{ $y($niceMax * $f) + 4 }}" text-anchor="end" font-size="11" fill="rgb(var(--c-muted))">{{ $tick($niceMax * $f) }}</text>
            @endforeach
            @foreach ($labels as $i => $label)
                @if ($i % $every === 0)
                    <text x="{{ $x($i) }}" y="{{ $H - 6 }}" text-anchor="{{ $i === 0 ? 'start' : 'middle' }}" font-size="11" fill="rgb(var(--c-muted))">{{ $label }}</text>
                @endif
            @endforeach
            @if ($n > 1)
                @if ($previous)
                    <path d="{{ $path($previous) }}" fill="none" stroke="rgb(var(--c-muted))" stroke-opacity=".55" stroke-width="1.5" stroke-dasharray="4 4" stroke-linejoin="round" />
                @endif
                <path class="adm-area" d="{{ $path($values) }} L {{ $x($n - 1) }} {{ $y(0) }} L {{ $x(0) }} {{ $y(0) }} Z" fill="url(#{{ $gradient }})" />
                <path class="adm-line" pathLength="1" d="{{ $path($values) }}" fill="none" stroke="rgb(var(--c-accent))" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
            @endif
        </svg>

        <template x-if="point">
            <div class="pointer-events-none absolute inset-0">
                <span class="absolute bottom-[26px] top-3 w-px bg-accent/40" :style="`left:${point.x * 100}%`"></span>
                <span class="absolute h-2.5 w-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-surface bg-accent" :style="`left:${point.x * 100}%;top:${point.y * 100}%`"></span>
                <div class="absolute top-1 z-10 min-w-[120px] rounded-lg border border-line bg-surface px-2.5 py-1.5 text-xs shadow-lg"
                     :style="point.x > .7 ? `right:${(1 - point.x) * 100 + 1.5}%` : `left:${point.x * 100 + 1.5}%`">
                    <p class="text-muted" x-text="point.label"></p>
                    <p class="font-semibold tabular-nums" x-text="point.value"></p>
                    <p x-show="point.prev" class="tabular-nums text-muted">Previous: <span x-text="point.prev"></span></p>
                </div>
            </div>
        </template>
    </div>

    <table class="sr-only">
        <caption>{{ $name }}</caption>
        <thead><tr><th>Date</th><th>{{ $name }}</th>@if ($previous)<th>{{ $previousName }}</th>@endif</tr></thead>
        <tbody>
            @foreach ($values as $i => $v)
                <tr><td>{{ $labels[$i] ?? '' }}</td><td>{{ $money($v) }}</td>@if ($previous)<td>{{ $money($previous[$i] ?? 0) }}</td>@endif</tr>
            @endforeach
        </tbody>
    </table>
</div>
