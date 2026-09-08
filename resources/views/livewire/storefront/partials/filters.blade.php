<div class="space-y-6 text-sm">
    <div>
        <label class="label" for="pb-q">Search</label>
        <div class="relative">
            <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 w-4 h-4 -translate-y-1/2 text-muted" />
            <input id="pb-q" wire:model.live.debounce.400ms="q" placeholder="Part, brand, OEM #" class="input h-10 pl-9 text-sm">
        </div>
    </div>

    <div>
        <p class="label">Category</p>
        <div class="space-y-1.5">
            <label class="flex cursor-pointer items-center gap-2">
                <input type="radio" wire:model.live="category" value="" class="text-accent focus:ring-accent"> <span class="text-muted">All categories</span>
            </label>
            @foreach ($categories as $cat)
                <label class="flex cursor-pointer items-center gap-2">
                    <input type="radio" wire:model.live="category" value="{{ $cat->slug }}" class="text-accent focus:ring-accent">
                    <span>{{ $cat->name }}</span>
                </label>
            @endforeach
        </div>
    </div>

    <div>
        <label class="label" for="pb-brand">Brand</label>
        <select id="pb-brand" wire:model.live="brand" class="input h-10 text-sm">
            <option value="">All brands</option>
            @foreach ($brands as $b)
                <option value="{{ $b->slug }}">{{ $b->name }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <p class="label">Condition</p>
        <div class="flex flex-wrap gap-2">
            @foreach (['' => 'Any', 'new' => 'New', 'used' => 'Used', 'refurbished' => 'Refurb'] as $val => $label)
                <button type="button" wire:click="$set('condition', '{{ $val }}')"
                        class="rounded-lg border px-2.5 py-1 text-xs font-medium transition
                               {{ $condition === $val ? 'border-accent bg-accent/10 text-accent' : 'border-line text-muted hover:border-fg/30' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    @if ($this->activeFilters)
        <button wire:click="clearFilters" class="btn-outline btn-sm w-full">Clear all filters</button>
    @endif
</div>
