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
        <div class="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($products as $product)
                <div class="card overflow-hidden" wire:key="wl-{{ $product->id }}">
                    <a href="{{ route('products.show', $product->slug) }}" class="block aspect-video overflow-hidden bg-surface-2">
                        <img src="{{ $product->getFirstMediaUrl('images', 'thumb') ?: $product->getFirstMediaUrl('images') }}" alt="{{ $product->title }}" class="h-full w-full object-cover">
                    </a>
                    <div class="p-4">
                        <a href="{{ route('products.show', $product->slug) }}" class="line-clamp-2 text-sm font-semibold hover:text-accent">{{ $product->title }}</a>
                        <div class="mt-2"><x-ui.price :price="$product->price" :sale="$product->liveSalePrice()" :basis="$product->priceBasis()" size="text-lg" /></div>
                        <div class="mt-3 flex gap-2">
                            <button wire:click="moveToCart({{ $product->id }})" class="btn-accent btn-sm flex-1">Move to cart</button>
                            <button wire:click="remove({{ $product->id }})" class="btn-outline btn-sm" aria-label="Remove"><x-ui.icon name="trash" class="w-4 h-4" /></button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
