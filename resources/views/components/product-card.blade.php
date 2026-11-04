@props(['product', 'href' => null])
@php
    $images = $product->getMedia('images');
    $img = $images->first()?->getUrl('thumb') ?: $product->getFirstMediaUrl('images');
    $img2 = $images->get(1)?->getUrl('thumb');
    $hasReviews = ($product->reviews_count ?? 0) > 0;
    $rating = $hasReviews
        ? (float) $product->avg_rating
        : (float) ($product->seller?->sellerProfile?->rating ?? 0);
    $ratingCount = $hasReviews ? $product->reviews_count : null;

    // Only a live deal counts — a scheduled or ended sale must not show as a discount.
    $sale = $product->liveSalePrice();
    $off = $sale ? (int) round((1 - $sale / max((float) $product->price, 0.01)) * 100) : 0;
    $endsSoon = $sale && $product->deal_ends_at && $product->deal_ends_at->lt(now()->addDays(3)) ? $product->deal_ends_at : null;
    $quickAdd = ! $product->hasVariants() && $product->quantity > 0 && ! $product->seller?->sellerProfile?->salesPaused();
@endphp

<div data-tilt {{ $attributes->merge(['class' => 'pm-tilt group card relative flex flex-col overflow-hidden hover:shadow-card-hover']) }}>
    <div class="pm-glare"></div>
    <div class="absolute right-3 top-3 z-10">
        <x-ui.wishlist-heart :product-id="$product->id" />
    </div>

    <a href="{{ $href ?? route('products.show', $product->slug) }}" data-morph class="flex flex-1 flex-col">
        <div class="pm-swap relative aspect-square overflow-hidden bg-surface-2">
            <img src="{{ $img }}" alt="{{ $product->title }}" loading="lazy" width="400" height="400"
                 class="h-full w-full object-cover transition-transform duration-500 ease-out group-hover:scale-[1.06]">
            @if ($img2)
                <img src="{{ $img2 }}" alt="" loading="lazy" width="400" height="400" class="h-full w-full object-cover group-hover:scale-[1.06]" aria-hidden="true">
            @endif

            <div class="absolute left-3 top-3 flex flex-col items-start gap-1">
                @if ($sale)
                    <span class="pm-shine rounded-full bg-accent px-2 py-0.5 text-[11px] font-bold text-accent-fg shadow-sm">−{{ $off }}%</span>
                @endif
                @if ($endsSoon)
                    <span class="rounded-full bg-rose-500 px-2 py-0.5 text-[10px] font-bold text-white shadow-sm">
                        Ends in {{ $endsSoon->diffForHumans(null, true, true, 1) }}
                    </span>
                @endif
            </div>

            @if ($product->quantity <= 0 && ! $product->hasVariants())
                <span class="absolute inset-x-0 bottom-0 bg-brand/85 py-1.5 text-center text-xs font-semibold text-brand-fg">Out of stock</span>
            @elseif ($product->quantity <= 3 && ! $product->hasVariants())
                <span class="absolute left-3 bottom-3 rounded bg-warning-bg px-1.5 py-0.5 text-[11px] font-semibold text-amber-600">Only {{ $product->quantity }} left</span>
            @endif
        </div>

        <div class="flex flex-1 flex-col p-3.5 sm:p-4">
            {{-- one quiet meta line keeps every card's title on the same baseline --}}
            <p class="h-4 truncate text-[11px] font-semibold uppercase tracking-[.12em] text-muted">
                {{ $product->brand?->name }}{{ $product->brand && $product->part_number ? ' · ' : '' }}{{ $product->part_number ? 'Part no. '.$product->part_number : '' }}
            </p>
            <h3 class="mt-1.5 line-clamp-2 min-h-[2.6em] text-[13.5px] font-medium leading-[1.3] text-fg transition-colors group-hover:text-accent">{{ $product->title }}</h3>

            <div class="mt-auto flex items-center justify-between gap-2 pt-3">
                <x-ui.price :price="$product->price" :sale="$sale" :basis="$product->priceBasis()" size="text-base" :badge="false" />
                @if ($rating > 0)
                    <x-ui.rating :value="$rating" :count="$ratingCount" size="w-3 h-3" />
                @endif
            </div>
        </div>
    </a>

    <div class="flex h-11 items-center justify-between gap-2 border-t border-line px-3.5 sm:px-4">
        <x-ui.compare-btn :product-id="$product->id" />
        @if ($quickAdd)
            {{-- Adds without leaving the page: flies the photo to the cart, then shows a drawn check. --}}
            <button type="button" x-data="{ s: 'idle' }"
                    @click.prevent="if (s !== 'idle') return; s = 'busy';
                        window.pmQuickAdd({{ $product->id }}, $el.closest('[data-tilt]'))
                            .then(() => { s = 'ok'; setTimeout(() => s = 'idle', 1500) })
                            .catch(() => s = 'idle')"
                    :class="s === 'ok' ? '!border-emerald-500 !bg-emerald-500 !text-white' : ''"
                    class="pm-tap relative inline-flex h-7 items-center gap-1 rounded-full border border-line px-2.5 text-[11px] font-semibold text-fg transition hover:border-accent hover:bg-accent hover:text-accent-fg active:scale-95"
                    aria-label="Add {{ $product->title }} to cart">
                <x-ui.icon name="cart" class="w-4 h-4" x-show="s === 'idle'" />
                <svg x-show="s === 'busy'" x-cloak class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".3" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                <svg x-show="s === 'ok'" x-cloak class="pm-check h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span x-text="s === 'ok' ? 'Added' : 'Add'">Add</span>
            </button>
        @elseif ($product->hasVariants())
            <a href="{{ $href ?? route('products.show', $product->slug) }}"
               class="pm-tap relative inline-flex h-7 items-center rounded-full border border-line px-2.5 text-[11px] font-semibold text-fg transition hover:border-accent hover:text-accent">Options</a>
        @endif
    </div>
</div>
