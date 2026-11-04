<div>
    <div id="pdp-anchor-{{ $product->id }}" class="hidden"></div>

    @php($hasVariants = $variants->isNotEmpty())
    @php($stock = $hasVariants ? (int) $variants->sum('quantity') : $product->quantity)

    @if ($hasVariants)
        <div class="mb-3">
            <label class="label" for="atc-variant-{{ $product->id }}">Option</label>
            <select id="atc-variant-{{ $product->id }}" wire:model.live="variantId" class="input">
                @foreach ($variants as $variant)
                    <option value="{{ $variant->id }}" @disabled($variant->quantity < 1)>
                        {{ $variant->label }} — ${{ number_format($variant->effectivePrice(), 2) }}{{ $variant->quantity < 1 ? ' (out of stock)' : ($variant->quantity <= 5 ? ' (only '.$variant->quantity.' left)' : '') }}
                    </option>
                @endforeach
            </select>
            @error('variantId') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
        </div>
    @endif

    @if ($paused)
        <button disabled class="btn-outline h-12 w-full !py-0 opacity-70">Seller is away — not taking orders</button>
    @elseif ($stock > 0)
        {{-- idle → adding (spinner) → added (drawn checkmark) → back to idle --}}
        <button type="button" data-magnetic="0.12"
                x-data="{ state: 'idle' }"
                @click="if (state !== 'idle') return;
                        state = 'adding';
                        window.flyToCart && window.flyToCart(document.getElementById('pdp-hero-{{ $product->id }}') || $el);
                        $wire.add().then((ok) => {
                            state = ok ? 'added' : 'idle';
                            if (ok) setTimeout(() => state = 'idle', 1700);
                        }).catch(() => state = 'idle')"
                :class="state === 'added' ? '!bg-emerald-500 !text-white !shadow-[0_8px_24px_-8px_rgb(16_185_129/.6)]' : ''"
                class="btn-accent relative h-12 w-full overflow-hidden !py-0 text-[15px] transition-colors duration-300">
            <span x-show="state === 'idle'" class="inline-flex items-center gap-2">
                <x-ui.icon name="cart" class="w-6 h-6" /> {{ $added ? 'Add another' : 'Add to cart' }}
            </span>
            <span x-show="state === 'adding'" x-cloak class="inline-flex items-center gap-2">
                <svg class="h-5 w-5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                Adding…
            </span>
            <span x-show="state === 'added'" x-cloak class="inline-flex items-center gap-2">
                <svg class="pm-check h-5 w-5" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Added to cart
            </span>
        </button>
    @else
        <button disabled class="btn-outline h-12 w-full !py-0 opacity-60">Out of stock</button>
    @endif

    @if ($added)
        <p class="mt-2 text-center text-xs font-medium text-emerald-600 dark:text-emerald-400" role="status">
            <x-ui.icon name="check" class="mr-0.5 inline w-4 h-4" /> In your cart —
            <a href="{{ route('cart.index') }}" class="underline">view cart</a>
        </p>
    @endif
</div>
