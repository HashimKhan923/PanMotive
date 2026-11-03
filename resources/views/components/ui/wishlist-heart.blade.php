@props(['productId', 'variant' => 'icon', 'size' => 'md'])

@if ($variant === 'button')
    <button type="button"
            x-data
            @click="$store.wishlist.toggle({{ $productId }})"
            :aria-pressed="$store.wishlist.has({{ $productId }})"
            class="btn-outline w-full"
            :class="$store.wishlist.has({{ $productId }}) ? '!border-accent !text-accent' : ''">
        <x-ui.icon name="heart" class="w-4 h-4" ::class="$store.wishlist.has({{ $productId }}) ? 'fill-current' : ''" />
        <span x-text="$store.wishlist.has({{ $productId }}) ? 'Saved' : 'Save for later'">Save for later</span>
    </button>
@else
    {{-- Saving pops the heart and sends out a ring — feedback you can see from across the grid. --}}
    <button type="button"
            x-data="{ pop: false }"
            @click.prevent.stop="const adding = ! $store.wishlist.has({{ $productId }});
                $store.wishlist.toggle({{ $productId }});
                if (adding) { pop = false; $nextTick(() => pop = true); setTimeout(() => pop = false, 520); }"
            :aria-pressed="$store.wishlist.has({{ $productId }})"
            aria-label="Save for later"
            class="relative grid place-items-center transition {{ $size === 'lg'
                ? 'h-12 w-12 rounded-xl border border-line bg-surface hover:border-rose-400'
                : 'h-8 w-8 rounded-full bg-surface/90 shadow-card backdrop-blur hover:scale-110' }}"
            :class="$store.wishlist.has({{ $productId }}) ? 'text-rose-500' : 'text-muted hover:text-fg'">
        <template x-if="pop"><span class="pm-burst"></span></template>
        <x-ui.icon name="heart" class="{{ $size === 'lg' ? 'w-5 h-5' : 'w-4 h-4' }}" ::class="($store.wishlist.has({{ $productId }}) ? 'fill-current ' : '') + (pop ? 'pm-heart-pop' : '')" />
    </button>
@endif
