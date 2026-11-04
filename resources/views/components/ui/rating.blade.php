@props(['value' => 0, 'count' => null, 'size' => 'w-4 h-4'])
@php $v = max(0, min(5, (float) $value)); $full = floor($v); @endphp

<div {{ $attributes->merge(['class' => 'inline-flex items-center gap-1']) }}
     role="img" aria-label="{{ number_format($v, 1) }} out of 5 stars">
    <div class="flex text-fg">
        @for ($i = 1; $i <= 5; $i++)
            <x-ui.icon name="star" :class="$size . ' ' . ($i <= $full ? 'fill-current' : 'text-line')" />
        @endfor
    </div>
    @if ($count !== null)
        <span class="text-xs text-muted">{{ number_format($v, 1) }} ({{ $count }})</span>
    @endif
</div>
