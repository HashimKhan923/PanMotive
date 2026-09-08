@props(['product'])
@php
    $img = $product->getFirstMediaUrl('images', 'thumb') ?: $product->getFirstMediaUrl('images');
    $rating = (float) ($product->seller?->sellerProfile?->rating ?? 0);
@endphp

<a href="{{ route('products.show', $product->slug) }}" wire:key="pc-{{ $product->id }}"
   class="group card flex flex-col overflow-hidden transition-shadow duration-200 hover:shadow-card-hover">
    <div class="relative aspect-square overflow-hidden bg-surface-2">
        <img src="{{ $img }}" alt="{{ $product->title }}" loading="lazy" width="400" height="400"
             class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-[1.04]">
        @if ($product->sale_price && $product->sale_price < $product->price)
            <span class="absolute left-3 top-3 rounded-full bg-accent px-2 py-0.5 text-[11px] font-bold text-accent-fg">Sale</span>
        @endif
        @if ($product->quantity <= 0)
            <span class="absolute inset-x-0 bottom-0 bg-brand/85 py-1.5 text-center text-xs font-semibold text-brand-fg">Out of stock</span>
        @elseif ($product->quantity <= 3)
            <span class="absolute left-3 bottom-3 rounded bg-surface/90 px-1.5 py-0.5 text-[11px] font-semibold text-fg">Only {{ $product->quantity }} left</span>
        @endif
    </div>

    <div class="flex flex-1 flex-col gap-1.5 p-4">
        @if ($product->brand)
            <span class="text-[11px] font-semibold uppercase tracking-wide text-muted">{{ $product->brand->name }}</span>
        @endif
        <h3 class="line-clamp-2 text-sm font-semibold leading-snug text-fg group-hover:text-accent">{{ $product->title }}</h3>

        @if ($product->part_number)
            <span class="text-xs text-muted">Part #{{ $product->part_number }}</span>
        @endif

        <div class="mt-auto flex items-center justify-between pt-2">
            <x-ui.price :price="$product->price" :sale="$product->sale_price" size="text-lg" />
            @if ($rating > 0)
                <x-ui.rating :value="$rating" size="w-3.5 h-3.5" />
            @endif
        </div>
    </div>
</a>
