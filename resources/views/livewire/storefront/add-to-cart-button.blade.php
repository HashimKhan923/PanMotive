<div>
    <div id="pdp-anchor-{{ $product->id }}" class="hidden"></div>
    @if ($product->quantity > 0)
        <button wire:click="add"
                onclick="window.flyToCart && window.flyToCart(document.querySelector('.container-x .card.aspect-square') || this)"
                wire:loading.attr="disabled" wire:target="add"
                class="btn-accent w-full !py-3.5 text-base">
            <span wire:loading.remove wire:target="add">
                <x-ui.icon name="cart" class="w-5 h-5" />
                {{ $added ? 'Add another' : 'Add to Cart' }}
            </span>
            <span wire:loading wire:target="add">Adding…</span>
        </button>
    @else
        <button disabled class="btn-outline w-full !py-3.5 opacity-60">Out of stock</button>
    @endif

    @if ($added)
        <p class="mt-2 text-center text-xs font-medium text-emerald-600 dark:text-emerald-400" role="status">
            <x-ui.icon name="check" class="mr-0.5 inline w-3.5 h-3.5" /> In your cart —
            <a href="{{ route('cart.index') }}" class="underline">view cart</a>
        </p>
    @endif
</div>
