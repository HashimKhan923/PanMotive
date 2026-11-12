@props(['placeholder' => 'Search parts, brands, or part numbers…', 'inputClass' => 'input h-10 rounded-full bg-surface-2/60 pl-9 pr-4 text-sm'])
{{-- Search box with suggestions while typing: parts (photo, price, fits your vehicle), categories,
     brands, and the shopper's recent searches. Keyboard: ↑ ↓ to move, Enter to open, Esc to close. --}}
@php($sid = 'pm-suggest-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6)))
<form action="{{ route('products.index') }}" method="GET" role="search"
      x-data="searchSuggest(@js(route('search.suggest')))"
      @submit="remember(q)" @click.outside="open = false" @keydown.escape="open = false"
      {{ $attributes->class('relative') }}>
    <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 w-4 h-4 -translate-y-1/2 text-muted" />
    <input name="q" x-model="q" value="{{ request('q') }}" placeholder="{{ $placeholder }}" autocomplete="off"
           class="{{ $inputClass }}" aria-label="Search products"
           role="combobox" aria-autocomplete="list" :aria-expanded="open" aria-controls="{{ $sid }}"
           @input.debounce.180ms="fetchSuggestions()" @focus="openPanel()"
           @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.enter="choose($event)">
    <span x-show="loading" x-cloak class="absolute right-3 top-1/2 -translate-y-1/2">
        <svg class="h-4 w-4 animate-spin text-muted" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
    </span>

    <div id="{{ $sid }}" data-suggest-panel x-show="open && hasContent()" x-cloak role="listbox"
         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
         class="absolute left-0 right-0 top-full z-50 mt-2 max-h-[70vh] overflow-y-auto rounded-2xl border border-line bg-surface p-2 text-left text-sm text-fg shadow-xl">

        {{-- recent searches (empty box) --}}
        <template x-if="q.trim().length < 2 && recent.length">
            <div>
                <div class="flex items-center justify-between px-2 py-1.5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-muted">Recent searches</p>
                    <button type="button" @click="clearRecent()" class="text-xs text-muted hover:text-accent">Clear</button>
                </div>
                <template x-for="(term, i) in recent" :key="'r'+term">
                    <a :href="searchUrl(term)" @click="remember(term)" role="option"
                       :class="active === i ? 'bg-accent/15' : 'hover:bg-surface-2'" @mouseenter="active = i"
                       class="flex items-center gap-2.5 rounded-lg px-2 py-2">
                        <x-ui.icon name="clock" class="h-4 w-4 shrink-0 text-muted" />
                        <span x-text="term" class="truncate"></span>
                    </a>
                </template>
            </div>
        </template>

        <template x-if="q.trim().length >= 2">
            <div>
                {{-- parts --}}
                <template x-if="results.products.length">
                    <div>
                        <p class="px-2 py-1.5 text-xs font-semibold uppercase tracking-wide text-muted">
                            Parts <span x-show="results.vehicle" class="normal-case tracking-normal" x-text="'· checking fit for your ' + results.vehicle"></span>
                        </p>
                        <template x-for="(p, i) in results.products" :key="'p'+p.url">
                            <a :href="p.url" @click="remember(q)" role="option" :aria-selected="active === i"
                               :class="active === i ? 'bg-accent/15' : 'hover:bg-surface-2'" @mouseenter="active = i"
                               class="flex items-center gap-3 rounded-lg px-2 py-2">
                                <span class="grid h-11 w-11 shrink-0 place-items-center overflow-hidden rounded-lg bg-surface-2 ring-1 ring-line">
                                    <template x-if="p.image"><img :src="p.image" alt="" class="h-full w-full object-cover" loading="lazy"></template>
                                    <template x-if="!p.image"><x-ui.icon name="box" class="h-5 w-5 text-muted" /></template>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="line-clamp-2 font-medium leading-snug" x-html="mark(p.title)"></span>
                                    <span class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-muted">
                                        <span x-show="p.part" x-text="'Part # ' + p.part"></span>
                                        <span x-show="p.fits === true" class="inline-flex items-center gap-1 font-semibold text-emerald-600"><x-ui.icon name="check" class="h-3.5 w-3.5" /> Fits your vehicle</span>
                                        <span x-show="!p.stock" class="text-rose-500">Out of stock</span>
                                    </span>
                                </span>
                                <span class="shrink-0 font-semibold" x-text="p.price"></span>
                            </a>
                        </template>
                    </div>
                </template>

                {{-- categories + brands --}}
                <template x-if="results.categories.length || results.brands.length">
                    <div class="mt-1 border-t border-line pt-1">
                        <template x-for="(c, j) in [...results.categories.map(x => ({...x, kind: 'Category'})), ...results.brands.map(x => ({...x, kind: 'Brand'}))]" :key="'c'+c.url">
                            <a :href="c.url" @click="remember(q)" role="option"
                               :class="active === results.products.length + j ? 'bg-accent/15' : 'hover:bg-surface-2'" @mouseenter="active = results.products.length + j"
                               class="flex items-center gap-2.5 rounded-lg px-2 py-2">
                                <x-ui.icon name="tag" class="h-4 w-4 shrink-0 text-muted" />
                                <span class="min-w-0 flex-1 truncate" x-html="mark(c.name)"></span>
                                <span class="text-xs text-muted" x-text="c.kind"></span>
                            </a>
                        </template>
                    </div>
                </template>

                {{-- nothing found --}}
                <template x-if="!loading && searched && !results.products.length && !results.categories.length && !results.brands.length">
                    <p class="px-3 py-4 text-center text-muted">No parts match “<span x-text="q"></span>” yet. Try a part number or a simpler word.</p>
                </template>

                <button type="submit" class="mt-1 flex w-full items-center justify-between rounded-lg border-t border-line px-2 py-2.5 font-medium hover:bg-surface-2">
                    <span>See all results for “<span x-text="q.trim()"></span>”</span>
                    <x-ui.icon name="arrow-right" class="h-4 w-4" />
                </button>
            </div>
        </template>
    </div>
</form>
