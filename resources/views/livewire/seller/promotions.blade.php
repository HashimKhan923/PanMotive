<div class="max-w-4xl">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">Promotions</h1>
            <p class="mt-1 text-sm text-muted">Run sales with strike-through prices, or hand out codes — all funded by you and applied only to your items.</p>
        </div>
        @if ($tab === 'sales')
            <button wire:click="newSale" class="btn-accent btn-sm"><x-ui.icon name="plus" class="w-4 h-4" /> New sale</button>
        @elseif ($tab === 'codes')
            <button wire:click="newPromotion" class="btn-accent btn-sm"><x-ui.icon name="plus" class="w-4 h-4" /> New code</button>
        @endif
    </div>

    <div class="mt-6 flex gap-1 border-b border-line text-sm">
        @foreach (['sales' => 'Sales', 'codes' => 'Coupon codes', 'private' => 'Private codes'] as $key => $label)
            <button wire:click="$set('tab', '{{ $key }}')"
                    class="-mb-px border-b-2 px-3 py-2 font-medium transition {{ $tab === $key ? 'border-accent text-accent' : 'border-transparent text-muted hover:text-fg' }}">
                {{ $label }}
                @if ($key === 'private' && $privateCodes->isNotEmpty())<span class="ml-1 rounded-full bg-surface-2 px-1.5 text-xs">{{ $privateCodes->count() }}</span>@endif
            </button>
        @endforeach
    </div>

    {{-- ========== SALES ========== --}}
    @if ($tab === 'sales')
        @if ($showSaleForm)
            <form wire:submit="createSale" class="card mt-6 space-y-4 p-6">
                <h2 class="font-bold">New sale</h2>
                <div class="grid gap-4 sm:grid-cols-4">
                    <div class="sm:col-span-2">
                        <label class="label" for="se-name">Name (shoppers see it)</label>
                        <input id="se-name" wire:model="saleName" class="input" placeholder="Brake Month Sale" maxlength="80">
                        @error('saleName') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="label" for="se-pct">Percent off</label>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ([10, 15, 20, 25, 30] as $pc)
                                <button type="button" wire:click="$set('salePercent', {{ $pc }})" class="rounded-lg border px-3 py-2 text-sm {{ (float) $salePercent === (float) $pc ? 'border-accent bg-accent/10 text-accent' : 'border-line' }}">{{ $pc }}%</button>
                            @endforeach
                            <input id="se-pct" type="number" step="1" min="5" max="80" wire:model.live="salePercent" class="input h-10 w-20" aria-label="Custom percent">
                        </div>
                        @error('salePercent') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="label" for="se-start">Starts</label>
                        <input id="se-start" type="datetime-local" wire:model="saleStarts" class="input">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="label" for="se-end">Ends</label>
                        <input id="se-end" type="datetime-local" wire:model="saleEnds" class="input">
                        @error('saleEnds') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <div class="flex flex-wrap items-end justify-between gap-2">
                        <label class="label mb-0">Listings <span class="text-muted">({{ count($saleProducts) }} selected)</span></label>
                        <div class="flex gap-2">
                            <input wire:model.live.debounce.300ms="saleSearch" class="input h-9 w-56 text-sm" placeholder="Filter by title or SKU" aria-label="Filter listings">
                            <button type="button" wire:click="selectAllProducts" class="btn-ghost btn-sm">Select all shown</button>
                            @if ($saleProducts)<button type="button" wire:click="$set('saleProducts', [])" class="btn-ghost btn-sm">Clear</button>@endif
                        </div>
                    </div>
                    <div class="mt-2 max-h-72 divide-y divide-line overflow-y-auto rounded-xl border border-line">
                        @forelse ($candidates as $p)
                            @php($onSale = $p->sale_price && (! $p->deal_ends_at || $p->deal_ends_at->isFuture()))
                            <label class="flex cursor-pointer items-center justify-between gap-3 px-3 py-2 text-sm hover:bg-surface-2" wire:key="sc-{{ $p->id }}">
                                <span class="flex min-w-0 items-center gap-2">
                                    <input type="checkbox" value="{{ $p->id }}" wire:model.live="saleProducts" class="rounded border-line text-accent focus:ring-accent">
                                    <span class="truncate">{{ $p->title }}</span>
                                    @if ($onSale)<span class="shrink-0 text-[10px] text-amber-600">already on sale</span>@endif
                                </span>
                                <span class="shrink-0 text-xs">
                                    <span class="text-muted line-through">${{ number_format($p->price, 2) }}</span>
                                    <span class="font-semibold text-accent">${{ number_format(round($p->price * (1 - (float) $salePercent / 100), 2), 2) }}</span>
                                </span>
                            </label>
                        @empty
                            <p class="p-6 text-center text-sm text-muted">No active listings match.</p>
                        @endforelse
                    </div>
                    @error('saleProducts') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
                <p class="text-xs text-muted">Shoppers see the original price struck through, a sale badge, and wishlisters get a price-drop alert. Prices go back automatically when the sale ends.</p>
                <div class="flex gap-2">
                    <button class="btn-accent btn-sm" wire:loading.attr="disabled" wire:target="createSale">Start sale</button>
                    <button type="button" wire:click="$set('showSaleForm', false)" class="btn-ghost btn-sm">Cancel</button>
                </div>
            </form>
        @endif

        <div class="mt-6 space-y-3">
            @forelse ($events as $e)
                @php($st = $e->liveStatus())
                @php($ep = $eventPerf[$e->id])
                <div class="card p-5" wire:key="se-{{ $e->id }}">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="font-semibold">{{ $e->name }} <span class="ml-1 text-accent">{{ rtrim(rtrim(number_format($e->percent_off, 2), '0'), '.') }}% off</span></p>
                            <p class="text-xs text-muted">{{ $e->items_count }} {{ Str::plural('listing', $e->items_count) }} · {{ $e->starts_at->format('M j, g:i A') }} – {{ $e->ends_at->format('M j, g:i A') }}</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ match ($st) { 'active' => 'bg-emerald-500/10 text-emerald-600', 'scheduled' => 'bg-accent/10 text-accent', default => 'bg-surface-2 text-muted' } }}">{{ ucfirst($st) }}</span>
                            @if (in_array($st, ['active', 'scheduled'], true))
                                <button wire:click="cancelSale({{ $e->id }})" wire:confirm="End this sale now? Prices go back to normal." class="text-sm text-muted hover:text-rose-500">{{ $st === 'active' ? 'End now' : 'Cancel' }}</button>
                            @endif
                        </div>
                    </div>
                    @if ($st !== 'scheduled')
                        <div class="mt-3 grid grid-cols-3 gap-3 border-t border-line pt-3 text-center text-sm">
                            <div><p class="text-xs text-muted">Orders</p><p class="font-bold">{{ $ep['orders'] }}</p></div>
                            <div><p class="text-xs text-muted">Units</p><p class="font-bold">{{ $ep['units'] }}</p></div>
                            <div><p class="text-xs text-muted">Sales</p><p class="font-bold">${{ number_format($ep['revenue'], 2) }}</p></div>
                        </div>
                    @endif
                </div>
            @empty
                @unless ($showSaleForm)
                    <div class="card grid place-items-center p-16 text-center">
                        <x-ui.icon name="tag" class="w-10 h-10 text-muted" />
                        <p class="mt-3 font-semibold">No sales yet</p>
                        <p class="mt-1 text-sm text-muted">Put a few listings — or your whole store — on sale for a weekend. No code needed; shoppers see the discount right on the listing.</p>
                        <button wire:click="newSale" class="btn-accent btn-sm mt-4">Create a sale</button>
                    </div>
                @endunless
            @endforelse
        </div>

    {{-- ========== PRIVATE CODES ========== --}}
    @elseif ($tab === 'private')
        <p class="mt-4 text-sm text-muted">One-person codes from accepted offers and customer thank-you coupons. They can be paused, but not edited — the buyer was promised these terms.</p>
        <div class="mt-4 overflow-x-auto rounded-xl border border-line">
            <table class="w-full min-w-[640px] text-sm">
                <thead class="bg-surface-2 text-left text-xs uppercase text-muted">
                    <tr><th class="px-4 py-3">Code</th><th class="px-4 py-3">For</th><th class="px-4 py-3">Discount</th><th class="px-4 py-3">Status</th><th class="px-4 py-3"></th></tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($privateCodes as $p)
                        <tr class="bg-surface" wire:key="pc-{{ $p->id }}">
                            <td class="px-4 py-3 font-mono font-semibold">{{ $p->code }}</td>
                            <td class="px-4 py-3">{{ $p->user?->name ?? '—' }}@if ($p->product)<span class="block text-xs text-muted">{{ Str::limit($p->product->title, 40) }}{{ $p->min_quantity > 1 ? ' × '.$p->min_quantity : '' }}</span>@endif</td>
                            <td class="px-4 py-3">{{ $p->type === 'percentage' ? rtrim(rtrim(number_format($p->value, 2), '0'), '.').'%' : '$'.number_format($p->value, 2) }} off</td>
                            <td class="px-4 py-3 text-xs">
                                @if ($p->usages_count > 0) <span class="text-emerald-600">Used</span>
                                @elseif ($p->expires_at?->isPast()) <span class="text-muted">Expired</span>
                                @elseif (! $p->is_active) <span class="text-muted">Paused</span>
                                @else Open until {{ $p->expires_at?->format('M j') ?? 'no end' }} @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if ($p->usages_count === 0 && ! $p->expires_at?->isPast())
                                    <button wire:click="togglePrivate({{ $p->id }})" class="text-sm text-muted hover:text-fg">{{ $p->is_active ? 'Pause' : 'Resume' }}</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-12 text-center text-sm text-muted">No private codes yet. They're created when you accept an offer or send a customer a coupon.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

    {{-- ========== CODES ========== --}}
    @else
    @if ($showForm)
        <form wire:submit="save" class="card mt-6 space-y-4 p-6">
            <h2 class="font-bold">{{ $editingId ? 'Edit promotion' : 'New promotion' }}</h2>
            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="label" for="pr-code">Code</label>
                    <input id="pr-code" wire:model="code" class="input uppercase">
                    @error('code') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="pr-type">Type</label>
                    <select id="pr-type" wire:model.live="type" class="input">
                        <option value="percentage">% off</option>
                        <option value="fixed">$ off</option>
                    </select>
                </div>
                <div>
                    <label class="label" for="pr-value">{{ $type === 'percentage' ? 'Percent off' : 'Amount off ($)' }}</label>
                    <input id="pr-value" type="number" step="0.01" wire:model="value" class="input">
                    @error('value') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="pr-min">Minimum order from your store ($)</label>
                    <input id="pr-min" type="number" step="0.01" wire:model="min_order_total" class="input" placeholder="No minimum">
                </div>
                <div>
                    <label class="label" for="pr-max">Max discount ($) <span class="text-muted">(% codes)</span></label>
                    <input id="pr-max" type="number" step="0.01" wire:model="max_discount_amount" class="input" placeholder="No cap">
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-4">
                <div>
                    <label class="label" for="pr-ul">Total uses</label>
                    <input id="pr-ul" type="number" wire:model="usage_limit" class="input" placeholder="∞">
                </div>
                <div>
                    <label class="label" for="pr-ulu">Per customer</label>
                    <input id="pr-ulu" type="number" wire:model="usage_limit_per_user" class="input">
                </div>
                <div>
                    <label class="label" for="pr-start">Starts</label>
                    <input id="pr-start" type="datetime-local" wire:model="starts_at" class="input">
                </div>
                <div>
                    <label class="label" for="pr-end">Ends</label>
                    <input id="pr-end" type="datetime-local" wire:model="expires_at" class="input">
                    @error('expires_at') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model="is_active" class="rounded border-line text-accent focus:ring-accent"> Active
            </label>
            <div class="flex gap-2">
                <button type="submit" class="btn-accent btn-sm">Save</button>
                <button type="button" wire:click="$set('showForm', false)" class="btn-ghost btn-sm">Cancel</button>
            </div>
        </form>
    @endif

    <div class="mt-6 overflow-x-auto rounded-xl border border-line">
        <table class="w-full min-w-[640px] text-sm">
            <thead class="bg-surface-2 text-left text-xs uppercase text-muted">
                <tr>
                    <th class="px-4 py-3">Code</th>
                    <th class="px-4 py-3">Discount</th>
                    <th class="px-4 py-3">Orders</th>
                    <th class="px-4 py-3">Your sales</th>
                    <th class="px-4 py-3">Window</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($promotions as $p)
                    <tr class="bg-surface" wire:key="promo-{{ $p->id }}">
                        <td class="px-4 py-3 font-mono font-semibold">{{ $p->code }}</td>
                        <td class="px-4 py-3">{{ $p->type === 'percentage' ? rtrim(rtrim(number_format($p->value,2),'0'),'.').'%' : '$'.number_format($p->value,2) }} off
                            @if ($p->min_order_total) <span class="text-xs text-muted">· min ${{ number_format($p->min_order_total,0) }}</span> @endif
                        </td>
                        @php($pf = $perf[$p->id] ?? null)
                        <td class="px-4 py-3 text-muted">{{ $p->usages_count }}{{ $p->usage_limit ? ' / '.$p->usage_limit : '' }}</td>
                        <td class="px-4 py-3">@if ($pf) ${{ number_format($pf->sales, 2) }} <span class="block text-xs text-muted">${{ number_format($pf->discount, 2) }} discounted</span> @else <span class="text-muted">—</span> @endif</td>
                        <td class="px-4 py-3 text-xs text-muted">
                            {{ $p->starts_at?->format('M j') ?? 'now' }} – {{ $p->expires_at?->format('M j, Y') ?? 'no end' }}
                        </td>
                        <td class="px-4 py-3">
                            <button wire:click="toggle({{ $p->id }})" class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $p->is_active ? 'bg-emerald-500/10 text-emerald-600' : 'bg-surface-2 text-muted' }}">
                                {{ $p->is_active ? 'Active' : 'Paused' }}
                            </button>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button wire:click="edit({{ $p->id }})" class="text-sm font-medium text-accent hover:underline">Edit</button>
                            <button wire:click="delete({{ $p->id }})" wire:confirm="Delete this promotion?" class="ml-3 text-sm text-muted hover:text-rose-500">Delete</button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-16 text-center">
                        <x-ui.icon name="tag" class="mx-auto w-10 h-10 text-muted" />
                        <p class="mt-3 font-semibold">No promotions yet</p>
                        <p class="mt-1 text-sm text-muted">Create a code to run a sale on your listings.</p>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @endif
</div>
