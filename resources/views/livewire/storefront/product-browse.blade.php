<div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
    <div class="flex items-center justify-between gap-4">
        <h1 class="text-2xl font-semibold">Shop</h1>
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search parts…"
               class="w-64 rounded-md border-slate-300 text-sm focus:border-slate-400 focus:ring-slate-400">
    </div>

    @if ($products->isEmpty())
        <div class="mt-10 rounded-lg border border-dashed border-slate-300 p-10 text-center text-slate-500">
            No active products yet.
        </div>
    @else
        <div class="mt-8 grid grid-cols-2 gap-6 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($products as $product)
                <a href="{{ route('products.show', $product->slug) }}"
                   class="group rounded-lg border border-slate-100 p-3 hover:shadow-sm" data-reveal>
                    <div class="aspect-square overflow-hidden rounded-md bg-slate-100">
                        <img src="{{ $product->getFirstMediaUrl('images') }}" alt="{{ $product->title }}"
                             class="h-full w-full object-cover transition group-hover:scale-105">
                    </div>
                    <h2 class="mt-3 line-clamp-2 text-sm font-medium">{{ $product->title }}</h2>
                    <p class="mt-1 text-sm text-slate-600">
                        ${{ number_format($product->sale_price ?? $product->price, 2) }}
                    </p>
                </a>
            @endforeach
        </div>

        <div class="mt-10">
            {{ $products->links() }}
        </div>
    @endif
</div>
