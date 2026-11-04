<div class="space-y-6 text-sm">
    <div>
        <label class="label" for="pb-q">Search</label>
        <div class="relative">
            <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 w-4 h-4 -translate-y-1/2 text-muted" />
            <input id="pb-q" wire:model.live.debounce.400ms="q" placeholder="Part, brand, vehicle, OEM #" class="input h-10 pl-9 text-sm">
        </div>
    </div>

    <div>
        <p class="label">Category</p>
        <div class="space-y-1.5">
            <label class="flex cursor-pointer items-center gap-2">
                <input type="radio" wire:model.live="category" value="" class="text-accent focus:ring-accent"> <span class="text-muted">All categories</span>
            </label>
            @foreach ($categories as $cat)
                <label class="flex cursor-pointer items-center gap-2" wire:key="cat-{{ $cat->id }}">
                    <input type="radio" wire:model.live="category" value="{{ $cat->slug }}" class="text-accent focus:ring-accent">
                    <span class="min-w-0 flex-1 truncate">{{ $cat->name }}</span>
                    <span class="text-xs tabular-nums text-muted">{{ $cat->listings_count }}</span>
                </label>
                @foreach ($cat->shown_children as $child)
                    <label class="ml-5 flex cursor-pointer items-center gap-2" wire:key="cat-{{ $child->id }}">
                        <input type="radio" wire:model.live="category" value="{{ $child->slug }}" class="text-accent focus:ring-accent">
                        <span class="min-w-0 flex-1 truncate text-muted">{{ $child->name }}</span>
                        <span class="text-xs tabular-nums text-muted">{{ $child->listings_count }}</span>
                    </label>
                @endforeach
            @endforeach
        </div>
    </div>

    <div>
        <label class="label" for="pb-brand">Brand</label>
        <select id="pb-brand" wire:model.live="brand" class="input h-10 text-sm">
            <option value="">All brands</option>
            @foreach ($brands as $b)
                <option value="{{ $b->slug }}">{{ $b->name }} ({{ $b->listings_count }})</option>
            @endforeach
        </select>
    </div>

    <div>
        <p class="label">Condition</p>
        <div class="flex flex-wrap gap-2">
            @foreach (['' => 'Any', 'new' => 'New', 'used' => 'Used', 'refurbished' => 'Refurb'] as $val => $label)
                @php($count = $val === '' ? $totalForCondition : (int) ($conditionCounts[$val] ?? 0))
                <button type="button" wire:click="$set('condition', '{{ $val }}')" @disabled($count === 0 && $condition !== $val)
                        class="rounded-lg border px-2.5 py-1 text-xs font-medium transition disabled:cursor-not-allowed disabled:opacity-40
                               {{ $condition === $val ? 'border-accent bg-surface-2 text-fg' : 'border-line text-muted hover:border-fg/30' }}">
                    {{ $label }} <span class="tabular-nums opacity-70">{{ $count }}</span>
                </button>
            @endforeach
        </div>
    </div>

    @if ($this->activeFilters)
        <button wire:click="clearFilters" class="btn-outline btn-sm w-full">Clear all filters</button>
    @endif
</div>
