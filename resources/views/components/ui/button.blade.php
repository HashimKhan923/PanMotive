@props([
    'variant' => 'accent',   // accent | brand | outline | ghost
    'size' => 'md',          // sm | md
    'href' => null,
    'icon' => null,
    'iconRight' => null,
])

@php
$classes = trim(implode(' ', [
    match ($variant) {
        'brand' => 'btn-brand',
        'outline' => 'btn-outline',
        'ghost' => 'btn-ghost',
        default => 'btn-accent',
    },
    $size === 'sm' ? 'btn-sm' : '',
]));
$tag = $href ? 'a' : 'button';
@endphp

<{{ $tag }} @if($href) href="{{ $href }}" @endif {{ $attributes->merge(['class' => $classes]) }}>
    @if($icon)<x-ui.icon :name="$icon" class="w-4 h-4" />@endif
    {{ $slot }}
    @if($iconRight)<x-ui.icon :name="$iconRight" class="w-4 h-4" />@endif
</{{ $tag }}>
