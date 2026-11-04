<div>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">Inventory</h1>
            <p class="mt-1 text-sm text-muted">Stock per listing and per option, warehouse locations, reorder alerts and a full movement history.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('seller.products.bulk-update') }}" class="btn-outline btn-sm"><x-ui.icon name="upload" class="w-4 h-4" /> Update by spreadsheet</a>
            <div class="relative">
                <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 w-4 h-4 -translate-y-1/2 text-muted" />
                <input wire:model.live.debounce.400ms="q" placeholder="Search title or SKU" class="input h-10 w-56 pl-9 text-sm" aria-label="Search inventory">
            </div>
        </div>
    </div>

    <div class="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="card p-4"><p class="text-xs uppercase tracking-wide text-muted">Units in stock</p><p class="mt-1 text-2xl font-bold">{{ number_format($totals['units']) }}</p></div>
        <div class="card p-4"><p class="text-xs uppercase tracking-wide text-muted">Stock value (at cost)</p><p class="mt-1 text-2xl font-bold">${{ number_format($totals['value'], 2) }}</p>
            @if ($totals['uncosted'])<p class="text-xs text-muted">{{ $totals['uncosted'] }} {{ Str::plural('listing', $totals['uncosted']) }} without a cost price</p>@endif</div>
        <button wire:click="$set('tab', 'low')" class="card p-4 text-left transition hover:border-accent"><p class="text-xs uppercase tracking-wide text-muted">Low stock</p><p class="mt-1 text-2xl font-bold {{ $lowCount ? 'text-amber-600' : '' }}">{{ $lowCount }}</p><p class="text-xs text-muted">at or below reorder point</p></button>
        <button wire:click="$set('tab', 'out')" class="card p-4 text-left transition hover:border-accent"><p class="text-xs uppercase tracking-wide text-muted">Out of stock</p><p class="mt-1 text-2xl font-bold {{ $outCount ? 'text-rose-600' : '' }}">{{ $outCount }}</p><p class="text-xs text-muted">active listings buyers can't order</p></button>
    </div>

    <div class="mt-5 flex flex-wrap gap-1 rounded-lg border border-line p-1 text-sm">
        @foreach (['levels' => 'Stock levels', 'low' => 'Low stock'.($lowCount ? " ($lowCount)" : ''), 'out' => 'Out of stock'.($outCount ? " ($outCount)" : ''), 'locations' => 'Locations', 'history' => 'Movement log'] as $key => $label)
            <button wire:click="$set('tab', '{{ $key }}')"
                class="rounded-md px-3 py-1 font-medium {{ $tab === $key ? 'bg-accent text-accent-fg' : 'text-muted hover:text-fg' }}">{{ $label }}</button>
        @endforeach
    </div>

    @if (in_array($tab, ['levels', 'low', 'out']))
        <div class="card mt-6 overflow-x-auto p-0">
            <table class="w-full min-w-[680px] text-sm">
                <thead class="text-xs uppercase tracking-wide text-muted">
                    <tr class="border-b border-line text-left">
                        <th class="p-3">Listing</th><th class="p-3 text-right">On hand</th>
                        <th class="p-3 text-right">Reorder at</th><th class="p-3 text-right">Target</th><th class="p-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($products as $p)
                        @php($hasOptions = $p->activeVariants->isNotEmpty())
                        <tr wire:key="inv-{{ $p->id }}" class="{{ $p->quantity <= 0 && $p->status === 'active' ? 'bg-rose-500/5' : ($p->isLowStock() ? 'bg-amber-500/5' : '') }}">
                            <td class="p-3">
                                <a href="{{ route('seller.products.edit', $p) }}" class="font-medium hover:text-accent">{{ $p->title }}</a>
                                @if ($p->sku)<span class="ml-1 font-mono text-xs text-muted">{{ $p->sku }}</span>@endif
                                @if ($p->quantity <= 0 && $p->status === 'active')<span class="pill ml-1 !bg-rose-500/15 !text-rose-600">Out</span>
                                @elseif ($p->isLowStock())<span class="pill ml-1 !bg-amber-500/15 !text-amber-700 dark:!text-amber-400">Low</span>@endif
                                @if ($hasOptions)<p class="text-xs text-muted">{{ $p->activeVariants->count() }} options — stock is set per option</p>@endif
                            </td>
                            <td class="p-3 text-right font-semibold">{{ $p->quantity }}</td>
                            <td class="p-3 text-right text-muted">{{ $p->reorder_point ?? '—' }}</td>
                            <td class="p-3 text-right text-muted">{{ $p->reorder_qty ?? '—' }}</td>
                            <td class="p-3 text-right">
                                @unless ($hasOptions)
                                    <div class="flex justify-end gap-2">
                                        @if ($p->isLowStock() && $p->reorder_qty)
                                            <button wire:click="restockToReorderQty({{ $p->id }})" class="btn-ghost btn-sm">Restock</button>
                                        @endif
                                        @if ($locations->count() > 1)
                                            <button wire:click="openTransfer({{ $p->id }})" class="btn-ghost btn-sm">Transfer</button>
                                        @endif
                                        <button wire:click="openAdjust({{ $p->id }})" class="btn-outline btn-sm">Adjust</button>
                                    </div>
                                @endunless
                            </td>
                        </tr>
                        @foreach ($p->activeVariants as $v)
                            <tr wire:key="inv-{{ $p->id }}-{{ $v->id }}" class="bg-surface-2/40">
                                <td class="p-3 pl-8 text-sm">
                                    <span class="text-muted">↳</span> {{ $v->label }}
                                    @if ($v->sku)<span class="ml-1 font-mono text-xs text-muted">{{ $v->sku }}</span>@endif
                                    @if ($v->quantity <= 0)<span class="pill ml-1 !bg-rose-500/15 !text-rose-600">Out</span>@endif
                                </td>
                                <td class="p-3 text-right font-semibold">{{ $v->quantity }}</td>
                                <td class="p-3"></td><td class="p-3"></td>
                                <td class="p-3 text-right">
                                    <div class="flex justify-end gap-2">
                                        @if ($locations->count() > 1)
                                            <button wire:click="openTransfer({{ $p->id }}, {{ $v->id }})" class="btn-ghost btn-sm">Transfer</button>
                                        @endif
                                        <button wire:click="openAdjust({{ $p->id }}, {{ $v->id }})" class="btn-outline btn-sm">Adjust</button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    @empty
                        <tr><td colspan="5" class="p-10 text-center text-muted">{{ match ($tab) { 'low' => 'Nothing is low on stock.', 'out' => 'Nothing is out of stock. 🎉', default => 'No listings.' } }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $products->links() }}</div>
    @endif

    @if ($tab === 'locations')
        <div class="mt-6 grid gap-6 lg:grid-cols-[1fr_320px]">
            <div class="space-y-3">
                @forelse ($locations as $loc)
                    <div class="card flex flex-wrap items-center justify-between gap-3 p-4" wire:key="loc-{{ $loc->id }}">
                        <div>
                            <p class="font-semibold">{{ $loc->name }} @if ($loc->is_default)<span class="pill ml-1 !bg-surface-2 !text-fg">Default</span>@endif</p>
                            <p class="text-xs text-muted">{{ $loc->code ? $loc->code.' · ' : '' }}{{ number_format((int) ($unitsByLocation[$loc->id] ?? 0)) }} units here</p>
                        </div>
                        <div class="flex gap-2">
                            @unless ($loc->is_default)<button wire:click="makeLocationDefault({{ $loc->id }})" class="btn-ghost btn-sm">Make default</button>@endunless
                            <button wire:click="editLocation({{ $loc->id }})" class="btn-outline btn-sm">Edit</button>
                            @unless ($loc->is_default)
                                <button wire:click="deleteLocation({{ $loc->id }})" wire:confirm="Delete {{ $loc->name }}?" class="btn-ghost btn-sm text-muted hover:text-rose-500" aria-label="Delete location"><x-ui.icon name="trash" class="w-4 h-4" /></button>
                            @endunless
                        </div>
                    </div>
                @empty
                    <div class="card p-8 text-center text-sm text-muted">No locations yet. Add one to track stock across warehouses.</div>
                @endforelse
            </div>
            <form wire:submit="saveLocation" class="card h-fit space-y-3 p-5">
                <h2 class="font-bold">{{ $locationId ? 'Edit location' : 'Add location' }}</h2>
                <div>
                    <label class="label" for="loc-name">Name</label>
                    <input id="loc-name" wire:model="locName" class="input">
                    @error('locName') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="loc-code">Code <span class="text-muted">(optional)</span></label>
                    <input id="loc-code" wire:model="locCode" class="input" placeholder="e.g. WEST">
                </div>
                <div class="flex gap-2">
                    <button class="btn-accent btn-sm">Save</button>
                    @if ($locationId)<button type="button" wire:click="$set('locationId', null)" class="btn-ghost btn-sm">Cancel</button>@endif
                </div>
            </form>
        </div>
    @endif

    @if ($tab === 'history')
        <div class="mt-6 flex justify-end">
            <button wire:click="exportMovements" class="btn-outline btn-sm"><x-ui.icon name="download" class="w-4 h-4" /> Export CSV</button>
        </div>
        <div class="card mt-3 overflow-x-auto p-0">
            <table class="w-full min-w-[760px] text-sm">
                <thead class="text-xs uppercase tracking-wide text-muted">
                    <tr class="border-b border-line text-left">
                        <th class="p-3">When</th><th class="p-3">Listing</th><th class="p-3">Reason</th>
                        <th class="p-3">Location</th><th class="p-3 text-right">Change</th><th class="p-3 text-right">Balance</th><th class="p-3">By</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($movements as $m)
                        <tr wire:key="mv-{{ $m->id }}">
                            <td class="whitespace-nowrap p-3 text-muted">{{ $m->created_at->format('M j, g:i a') }}</td>
                            <td class="p-3">
                                {{ $m->product?->title }}@if ($m->variant) <span class="text-muted">— {{ $m->variant->label }}</span>@endif
                                @if ($m->reference || $m->note)<p class="text-xs text-muted">{{ trim(($m->reference ?? '').' '.($m->note ?? '')) }}</p>@endif
                            </td>
                            <td class="p-3"><span class="pill capitalize">{{ $m->reason }}</span></td>
                            <td class="p-3 text-muted">{{ $m->location?->name ?? '—' }}</td>
                            <td class="p-3 text-right font-semibold {{ $m->delta < 0 ? 'text-rose-500' : 'text-emerald-600' }}">{{ $m->delta > 0 ? '+' : '' }}{{ $m->delta }}</td>
                            <td class="p-3 text-right">{{ $m->balance_after }}</td>
                            <td class="p-3 text-muted">{{ $m->actor_name ?? 'System' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="p-10 text-center text-muted">No stock movements recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $movements->links() }}</div>
    @endif

    {{-- adjust modal --}}
    @if ($adjustListing)
        <div class="fixed inset-0 z-50 grid place-items-center bg-black/50 p-4" wire:click.self="$set('adjustProductId', null)">
            <div class="card w-full max-w-md p-6">
                <h2 class="font-bold">Adjust stock</h2>
                <p class="mt-1 text-sm text-muted">{{ $adjustListing->title }}@if ($adjustVariant) — <span class="font-medium text-fg">{{ $adjustVariant->label }}</span>@endif
                    · {{ $adjustVariant ? $adjustVariant->quantity : $adjustListing->quantity }} on hand</p>
                <form wire:submit="applyAdjust" class="mt-4 space-y-3">
                    <div class="grid grid-cols-2 gap-1 rounded-lg border border-line p-1 text-sm">
                        <button type="button" wire:click="$set('adjustMode', 'change')" class="rounded-md px-3 py-1.5 font-medium {{ $adjustMode === 'change' ? 'bg-accent text-accent-fg' : 'text-muted' }}">Add / remove</button>
                        <button type="button" wire:click="$set('adjustMode', 'set')" class="rounded-md px-3 py-1.5 font-medium {{ $adjustMode === 'set' ? 'bg-accent text-accent-fg' : 'text-muted' }}">Set exact count</button>
                    </div>
                    @if ($adjustMode === 'change')
                        <div>
                            <label class="label" for="adj-delta">Change (use a negative number to remove)</label>
                            <input id="adj-delta" type="number" wire:model="adjustDelta" class="input">
                            @error('adjustDelta') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label" for="adj-reason">Reason</label>
                            <select id="adj-reason" wire:model="adjustReason" class="input">
                                @foreach (array_diff(\App\Models\StockMovement::REASONS, ['sale']) as $r)
                                    <option value="{{ $r }}">{{ ucfirst($r) }}</option>
                                @endforeach
                            </select>
                        </div>
                    @else
                        <div>
                            <label class="label" for="adj-target">Counted on the shelf</label>
                            <input id="adj-target" type="number" min="0" wire:model="adjustTarget" class="input">
                            @error('adjustTarget') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                            <p class="mt-1 text-xs text-muted">We'll log the difference as a stock-count correction.</p>
                        </div>
                    @endif
                    @if ($locations->count() > 1)
                        <div>
                            <label class="label" for="adj-loc">Location</label>
                            <select id="adj-loc" wire:model="adjustLocationId" class="input">
                                @foreach ($locations as $loc)<option value="{{ $loc->id }}">{{ $loc->name }}</option>@endforeach
                            </select>
                        </div>
                    @endif
                    <div>
                        <label class="label" for="adj-note">Note <span class="text-muted">(optional)</span></label>
                        <input id="adj-note" wire:model="adjustNote" class="input" maxlength="200">
                    </div>
                    <div class="flex gap-2">
                        <button class="btn-accent btn-sm">Apply</button>
                        <button type="button" wire:click="$set('adjustProductId', null)" class="btn-ghost btn-sm">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- transfer modal --}}
    @if ($transferListing)
        <div class="fixed inset-0 z-50 grid place-items-center bg-black/50 p-4" wire:click.self="$set('transferProductId', null)">
            <div class="card w-full max-w-md p-6">
                <h2 class="font-bold">Transfer between locations</h2>
                <p class="mt-1 text-sm text-muted">{{ $transferListing->title }}</p>
                <form wire:submit="applyTransfer" class="mt-4 space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="label" for="tr-from">From</label>
                            <select id="tr-from" wire:model="transferFrom" class="input">
                                @foreach ($locations as $loc)<option value="{{ $loc->id }}">{{ $loc->name }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label class="label" for="tr-to">To</label>
                            <select id="tr-to" wire:model="transferTo" class="input">
                                @foreach ($locations as $loc)<option value="{{ $loc->id }}">{{ $loc->name }}</option>@endforeach
                            </select>
                            @error('transferTo') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div>
                        <label class="label" for="tr-qty">Units</label>
                        <input id="tr-qty" type="number" min="1" wire:model="transferQty" class="input">
                        @error('transferQty') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex gap-2">
                        <button class="btn-accent btn-sm">Move stock</button>
                        <button type="button" wire:click="$set('transferProductId', null)" class="btn-ghost btn-sm">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
