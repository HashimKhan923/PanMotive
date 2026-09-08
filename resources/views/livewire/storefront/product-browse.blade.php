@php($results = $this->results)
<div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h1 class="text-2xl font-semibold">Shop</h1>
        <div class="flex flex-wrap gap-2">
            <label class="sr-only" for="pb-search">Search parts</label>
            <input id="pb-search" type="search" wire:model.live.debounce.300ms="q" placeholder="Search parts…"
                   class="w-56 rounded-md border-slate-300 text-sm focus:border-slate-400 focus:ring-slate-400">
            <label class="sr-only" for="pb-sort">Sort</label>
            <select id="pb-sort" wire:model.live="sort" class="rounded-md border-slate-300 text-sm">
                <option value="">Relevance</option>
                <option value="price_asc">Price: low to high</option>
                <option value="price_desc">Price: high to low</option>
            </select>
        </div>
    </div>

    @if ($fallbackNotice)
        <p class="mt-4 rounded-md bg-amber-50 px-4 py-2 text-sm text-amber-800">{{ $fallbackNotice }}</p>
    @endif

    {{-- Loading skeletons (perceived performance + CLS). --}}
    <div wire:loading.delay class="mt-8 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
        @for ($i = 0; $i < 8; $i++)
            <x-skeleton-card />
        @endfor
    </div>

    <div wire:loading.remove>
        @if ($results->isEmpty())
            <div class="mt-10 rounded-lg border border-dashed border-slate-300 p-10 text-center text-slate-500">
                No matching products.
            </div>
        @else
            <div data-reveal-group class="mt-8 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($results as $product)
                    <a href="{{ route('products.show', $product->slug) }}"
                       class="group rounded-lg border border-slate-100 p-3 transition hover:shadow-sm" wire:key="p-{{ $product->id }}">
                        <div class="aspect-square overflow-hidden rounded-md bg-slate-100">
                            <img src="{{ $product->getFirstMediaUrl('images', 'thumb') ?: $product->getFirstMediaUrl('images') }}"
                                 alt="{{ $product->title }}" loading="lazy" width="300" height="300"
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
                {{ $results->links() }}
            </div>
        @endif
    </div>
</div>
