@props([
    'label',
    'value' => 0,        // number, or a ready-made string
    'prefix' => '',
    'suffix' => '',
    'decimals' => 0,
    'delta' => null,     // percent change against the previous period
    'icon' => null,
    'href' => null,
    'hint' => null,
])
@php
    $isNumber = is_numeric($value);
    $shown = $isNumber ? $prefix.number_format((float) $value, $decimals).$suffix : $value;
    $tag = $href ? 'a' : 'div';
@endphp
<{{ $tag }} @if ($href) href="{{ $href }}" @endif {{ $attributes->class('adm-card block p-4') }}>
    <div class="flex items-center justify-between gap-2">
        <p class="truncate text-xs font-medium text-muted">{{ $label }}</p>
        @if ($icon)
            <span class="grid h-7 w-7 shrink-0 place-items-center rounded-lg bg-accent/10 text-accent"><x-ui.icon :name="$icon" class="w-3.5 h-3.5" /></span>
        @endif
    </div>
    <p class="mt-1.5 truncate text-2xl font-bold tabular-nums tracking-tight"
       @if ($isNumber) data-count="{{ (float) $value }}" data-prefix="{{ $prefix }}" data-suffix="{{ $suffix }}" data-decimals="{{ $decimals }}" @endif>{{ $shown }}</p>
    @if ($delta !== null)
        <p class="mt-1 text-xs text-muted">
            <span @class(['font-semibold', 'text-emerald-600 dark:text-emerald-400' => $delta > 0, 'text-rose-600 dark:text-rose-400' => $delta < 0])>
                {{ $delta > 0 ? '▲' : ($delta < 0 ? '▼' : '') }} {{ number_format(abs($delta), 1) }}%
            </span>
            vs previous period
        </p>
    @elseif ($hint)
        <p class="mt-1 truncate text-xs text-muted">{{ $hint }}</p>
    @endif
</{{ $tag }}>
