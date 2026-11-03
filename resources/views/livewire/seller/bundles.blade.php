<div class="max-w-4xl">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">Bundles</h1>
            <p class="mt-1 text-sm text-muted">Group parts buyers fit together and offer a saving. Shows on each member's product page.</p>
        </div>
        <button wire:click="newBundle" class="btn-accent btn-sm"><x-ui.icon name="plus" class="w-4 h-4" /> New bundle</button>
    </div>

    @if ($showForm)
        <form wire:submit="save" class="card mt-6 space-y-4 p-6" data-reveal>
            <h2 class="font-bold">{{ $editingId ? 'Edit bundle' : 'New bundle' }}</h2>
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <label class="label" for="bn-title">Title</label>
                    <input id="bn-title" wire:model="title" class="input" placeholder="Front brake job — pads + rotors">
                    @error('title') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
                <div class="flex gap-2">
                    <div class="w-24">
                        <label class="label" for="bn-dt">Type</label>
                        <select id="bn-dt" wire:model.live="discount_type" class="input">
                            <option value="percentage">%</option>
                            <option value="fixed">$</option>
                        </select>
                    </div>
                    <div class="flex-1">
                        <label class="label" for="bn-dv">Off</label>
                        <input id="bn-dv" type="number" step="0.01" wire:model.live.debounce.300ms="discount_value" class="input">
                        @error('discount_value') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            <div>
                <div class="flex items-center justify-between">
                    <span class="label mb-0">Products (2+)</span>
                    <button type="button" wire:click="addLine" class="text-xs font-medium text-accent">+ Add product</button>
                </div>
                <div class="mt-2 space-y-2">
                    @foreach ($lines as $i => $line)
                        <div wire:key="bl-{{ $i }}" class="flex gap-2">
                            <select wire:model.live="lines.{{ $i }}.product_id" class="input h-10 flex-1 text-sm">
                                <option value="">Choose a listing…</option>
                                @foreach ($products as $p)
                                    <option value="{{ $p->id }}">{{ $p->title }} (${{ number_format($p->effectivePrice(), 2) }}){{ $p->status !== 'active' ? ' — not active' : '' }}</option>
                                @endforeach
                            </select>
                            <input type="number" min="1" max="50" wire:model.live.debounce.300ms="lines.{{ $i }}.quantity" class="input h-10 w-16 text-sm" aria-label="Quantity">
                            <button type="button" wire:click="removeLine({{ $i }})" class="btn-ghost !p-2 text-muted hover:text-rose-500"><x-ui.icon name="trash" class="w-4 h-4" /></button>
                        </div>
                        @error("lines.$i.product_id") <p class="-mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                    @endforeach
                </div>
                @error('lines') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
            </div>

            @if ($preview && $preview['full'] > 0)
                <div class="flex flex-wrap items-center gap-x-6 gap-y-1 rounded-xl bg-accent/5 px-4 py-3 text-sm">
                    <span class="text-muted">Bought separately <span class="font-semibold text-fg line-through">${{ number_format($preview['full'], 2) }}</span></span>
                    <span class="text-muted">Bundle price <span class="text-lg font-bold text-accent">${{ number_format($preview['price'], 2) }}</span></span>
                    <span class="text-emerald-600">Buyer saves ${{ number_format($preview['savings'], 2) }}</span>
                    <span class="w-full text-xs text-muted">The saving comes off your payout for those items, and our commission is charged on the bundle price.</span>
                </div>
            @endif

            <div class="flex gap-2">
                <button type="submit" class="btn-accent btn-sm">Save bundle</button>
                <button type="button" wire:click="$set('showForm', false)" class="btn-ghost btn-sm">Cancel</button>
            </div>
        </form>
    @endif

    <div class="mt-6 space-y-3" data-reveal-group>
        @forelse ($bundles as $bundle)
            <div class="card p-5" wire:key="bundle-{{ $bundle->id }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-semibold">{{ $bundle->title }}</p>
                        <p class="mt-1 text-sm text-muted">
                            {{ $bundle->products->pluck('title')->join(' + ') }}
                        </p>
                        @if ($bundle->is_active && ! $bundle->isAvailable())
                            @php($missing = $bundle->products->reject(fn ($p) => $p->status === 'active' && $p->sellableQuantity() >= (int) $p->pivot->quantity))
                            <p class="mt-2 inline-flex items-center gap-1.5 rounded-lg bg-amber-500/10 px-2 py-1 text-xs text-amber-700 dark:text-amber-400">
                                <x-ui.icon name="alert" class="w-3.5 h-3.5" />
                                Hidden from buyers — {{ $missing->pluck('title')->join(', ') }} {{ $missing->count() > 1 ? 'are' : 'is' }} out of stock or not active.
                            </p>
                        @endif
                        <p class="mt-1 text-sm">
                            <span class="text-muted line-through">${{ number_format($bundle->fullPrice(), 2) }}</span>
                            <span class="ml-1 font-bold text-accent">${{ number_format($bundle->discountedPrice(), 2) }}</span>
                            <span class="ml-1 text-xs text-emerald-600">save ${{ number_format($bundle->savings(), 2) }}</span>
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <button wire:click="toggle({{ $bundle->id }})" class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $bundle->is_active ? 'bg-emerald-500/10 text-emerald-600' : 'bg-surface-2 text-muted' }}">
                            {{ $bundle->is_active ? 'Active' : 'Paused' }}
                        </button>
                        <button wire:click="edit({{ $bundle->id }})" class="text-sm font-medium text-accent hover:underline">Edit</button>
                        <button wire:click="delete({{ $bundle->id }})" wire:confirm="Delete bundle?" class="text-muted hover:text-rose-500"><x-ui.icon name="trash" class="w-4 h-4" /></button>
                    </div>
                </div>
            </div>
        @empty
            <div class="card grid place-items-center p-16 text-center">
                <x-ui.icon name="box" class="w-10 h-10 text-muted" />
                <p class="mt-3 font-semibold">No bundles yet</p>
                <p class="mt-1 text-sm text-muted">Pair a common repair's parts and reward buyers for taking both.</p>
            </div>
        @endforelse
    </div>
</div>
