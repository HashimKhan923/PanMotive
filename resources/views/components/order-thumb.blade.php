@props(['item', 'size' => 'h-12 w-12'])
{{-- A line's product photo in seller and admin order screens. A box icon shows when there is no
     photo, or if the photo fails to load (the image removes itself and the icon underneath shows). --}}
@php($src = $item->imageUrl())
<span {{ $attributes->class("relative grid shrink-0 place-items-center overflow-hidden rounded-lg bg-surface-2 text-muted ring-1 ring-line {$size}") }}>
    <x-ui.icon name="package" class="w-5 h-5" />
    @if ($src)
        <img src="{{ $src }}" alt="{{ $item->product_title }}" loading="lazy" decoding="async"
             class="absolute inset-0 h-full w-full bg-surface object-cover" onerror="this.remove()">
    @endif
</span>
