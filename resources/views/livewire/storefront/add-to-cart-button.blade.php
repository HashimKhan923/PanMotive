<div id="pdp-image-anchor-{{ $product->id }}">
    <button wire:click="add"
            onclick="window.flyToCart && window.flyToCart(this.closest('div'))"
            class="w-full rounded-lg bg-amber-500 px-6 py-3 font-semibold text-slate-900 transition-transform hover:bg-amber-400 active:scale-95">
        <span wire:loading.remove wire:target="add">{{ $added ? 'Added ✓ — add another' : 'Add to Cart' }}</span>
        <span wire:loading wire:target="add">Adding…</span>
    </button>

    @if ($added)
        <p class="mt-2 text-center text-xs text-emerald-600" role="status">
            In your cart · <a href="{{ route('cart.index') }}" class="underline">view cart</a>
        </p>
    @endif
</div>
