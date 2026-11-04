<div class="container-x py-8 lg:py-12" x-data="{ filters: false }">
    {{-- header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <nav class="text-xs text-muted">
                <a href="{{ route('home') }}" class="hover:text-accent">Home</a> <span class="mx-1">/</span>
                <a href="{{ route('products.index') }}" class="hover:text-accent">Shop</a>
                @if ($parentCategory)
                    <span class="mx-1">/</span> <a href="{{ route('products.index', ['category' => $parentCategory->slug]) }}" class="hover:text-accent">{{ $parentCategory->name }}</a>
                @endif
            </nav>
            <h1 class="mt-1 text-2xl font-bold sm:text-3xl">
                {{ $category ? ($selectedCategory?->name ?? 'Category not found') : 'All Parts' }}
            </h1>
            <p class="mt-1 text-sm text-muted">{{ number_format($results->total()) }} {{ Str::plural('result', $results->total()) }}</p>
        </div>

        <div class="flex items-center gap-2">
            <button @click="filters = true" class="btn-outline btn-sm lg:hidden">
                <x-ui.icon name="filter" class="w-4 h-4" /> Filters
                @if ($this->activeFilters)<span class="ml-1 rounded-full bg-accent px-1.5 text-[10px] text-accent-fg">{{ $this->activeFilters }}</span>@endif
            </button>
            <label class="sr-only" for="pb-sort">Sort</label>
            <select id="pb-sort" wire:model.live="sort" class="input !w-auto !py-2 text-sm">
                <option value="">Relevance</option>
                <option value="newest">Newest</option>
                <option value="price_asc">Price: Low to High</option>
                <option value="price_desc">Price: High to Low</option>
            </select>
        </div>
    </div>

    @if ($this->chips)
        <div class="mt-4 flex flex-wrap items-center gap-2">
            @foreach ($this->chips as $chip)
                <button type="button" wire:click="removeFilter('{{ $chip['filter'] }}')" wire:key="chip-{{ $chip['filter'] }}"
                        class="inline-flex items-center gap-1.5 rounded-full border border-line bg-surface px-3 py-1 text-xs font-medium hover:border-accent hover:text-accent"
                        aria-label="Remove filter {{ $chip['label'] }}">
                    {{ $chip['label'] }} <x-ui.icon name="x" class="w-3 h-3" />
                </button>
            @endforeach
        </div>
    @endif

    @if ($fallbackNotice)
        <p class="mt-4 rounded-xl bg-surface-2 px-4 py-2.5 text-sm text-fg">
            <x-ui.icon name="sparkles" class="mr-1 inline w-4 h-4" /> {{ $fallbackNotice }}
        </p>
    @endif

    <div class="mt-6 grid gap-8 lg:grid-cols-[260px_1fr]">
        {{-- ===== FILTER SIDEBAR ===== --}}
        <aside class="hidden lg:block">
            @include('livewire.storefront.partials.filters')
        </aside>

        {{-- mobile filter drawer --}}
        <div x-show="filters" x-cloak class="fixed inset-0 z-50 lg:hidden">
            <div class="absolute inset-0 bg-black/50" @click="filters = false"></div>
            <div class="absolute left-0 top-0 h-full w-80 max-w-[85vw] overflow-y-auto bg-surface p-5">
                <div class="mb-4 flex items-center justify-between">
                    <span class="font-bold">Filters</span>
                    <button @click="filters = false" class="btn-ghost !p-2"><x-ui.icon name="x" class="w-5 h-5" /></button>
                </div>
                @include('livewire.storefront.partials.filters')
            </div>
        </div>

        {{-- ===== RESULTS ===== --}}
        <div>
            @php($sponsored = $this->sponsored)
            @if ($sponsored->isNotEmpty())
                <div class="mb-4">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">Sponsored</p>
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                        @foreach ($sponsored as $ad)
                            <div class="relative" wire:key="spon-{{ $ad['source'] }}-{{ $ad['ref_id'] }}">
                                <span class="absolute left-2 top-2 z-10 rounded-full bg-brand/85 px-2 py-0.5 text-[10px] font-semibold text-brand-fg">Ad</span>
                                <x-product-card :product="$ad['product']"
                                    :href="route('ad.click', ['source' => $ad['source'], 'ref' => $ad['ref_id'], 'p' => $ad['product']->id])" />
                                @if ($ad['headline'])
                                    <p class="mt-1 line-clamp-1 px-1 text-xs font-medium text-accent">{{ $ad['headline'] }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div wire:loading.delay.class="opacity-40" class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                @forelse ($results as $product)
                    <x-product-card :product="$product" />
                @empty
                    <div class="col-span-full card grid place-items-center p-16 text-center">
                        <x-ui.icon name="search" class="w-10 h-10 text-muted" />
                        <p class="mt-3 font-semibold">No parts match your filters</p>
                        <p class="mt-1 text-sm text-muted">Try a different search or clear the filters.</p>
                        <button wire:click="clearFilters" class="btn-outline btn-sm mt-4">Clear all filters</button>
                    </div>
                @endforelse

                {{-- skeletons while loading --}}
                <template wire:loading.delay>
                    @for ($i = 0; $i < 6; $i++)
                        <div class="card overflow-hidden p-0">
                            <div class="skeleton aspect-square rounded-none"></div>
                            <div class="space-y-2 p-4"><div class="skeleton h-3 w-3/4"></div><div class="skeleton h-4 w-1/3"></div></div>
                        </div>
                    @endfor
                </template>
            </div>

            @if ($results->hasPages())
                <div class="mt-10">{{ $results->links() }}</div>
            @endif
        </div>
    </div>
</div>
