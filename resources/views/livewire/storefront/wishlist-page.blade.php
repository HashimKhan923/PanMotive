<div class="container-x py-10" data-reveal>
    <h1 class="text-2xl font-bold sm:text-3xl">Saved for later</h1>
    <p class="mt-1 text-sm text-muted">{{ $products->count() }} {{ Str::plural('item', $products->count()) }}</p>

    @if ($products->isEmpty())
        <div class="mt-8 card grid place-items-center p-16 text-center">
            <x-ui.icon name="heart" class="w-12 h-12 text-muted" />
            <p class="mt-4 text-lg font-semibold">Nothing saved yet</p>
            <p class="mt-1 text-sm text-muted">Tap the heart on any part to keep it here.</p>
            <a href="{{ route('products.index') }}" class="btn-accent mt-6">Browse parts</a>
        </div>
    @else
        {{-- Same card as the home page, with the saved-list actions underneath. --}}
        <div class="mt-8 grid grid-cols-2 gap-5 sm:grid-cols-3 lg:grid-cols-4" data-reveal-group>
            @foreach ($products as $product)
                <div class="flex flex-col" wire:key="wl-{{ $product->id }}">
                    <x-product-card :product="$product" class="flex-1" />
                    <div class="mt-2 flex gap-2">
                        <button wire:click="moveToCart({{ $product->id }})" class="btn-accent btn-sm flex-1">Move to cart</button>
                        <button wire:click="remove({{ $product->id }})" class="btn-outline btn-sm" aria-label="Remove {{ $product->title }}"><x-ui.icon name="trash" class="w-4 h-4" /></button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
