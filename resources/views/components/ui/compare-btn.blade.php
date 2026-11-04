@props(['productId', 'variant' => 'link'])

@if ($variant === 'button')
    <button type="button" x-data
            @click="$store.compare.toggle({{ $productId }})"
            class="btn-outline w-full"
            :class="$store.compare.has({{ $productId }}) ? '!border-accent text-fg' : ''">
        <x-ui.icon name="grid" class="w-4 h-4" />
        <span x-text="$store.compare.has({{ $productId }}) ? 'In compare' : ($store.compare.full && !$store.compare.has({{ $productId }}) ? 'Compare full' : 'Add to compare')">Add to compare</span>
    </button>
@else
    <button type="button" x-data
            @click.prevent.stop="$store.compare.toggle({{ $productId }})"
            class="pm-tap relative inline-flex items-center gap-1.5 text-xs font-medium transition"
            :class="$store.compare.has({{ $productId }}) ? 'text-fg' : 'text-muted hover:text-fg'">
        <x-ui.icon name="grid" class="w-4 h-4" />
        <span x-text="$store.compare.has({{ $productId }}) ? 'Comparing' : 'Compare'">Compare</span>
    </button>
@endif
