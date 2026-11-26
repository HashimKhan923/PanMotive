<div class="max-w-6xl">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">Supply Network</h1>
            <p class="mt-1 max-w-2xl text-sm text-muted">Sell other stores' parts without holding stock, or let other stores sell yours. The supplier ships every order; the reseller earns a margin; Panmotive pays both after delivery.</p>
        </div>
    </div>

    @php
        $tabs = [
            'find' => 'Find suppliers',
            'listings' => 'My resold listings',
            'sales' => 'Resale sales',
            'offer' => 'Offer my products',
            'resellers' => 'My resellers'.($pendingRequests ? " ({$pendingRequests})" : ''),
        ];
    @endphp
    <nav class="mt-6 flex flex-wrap gap-1 border-b border-line" aria-label="Supply Network sections">
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')"
                    class="-mb-px border-b-2 px-3 py-2 text-sm font-medium transition {{ $tab === $key ? 'border-accent text-fg' : 'border-transparent text-muted hover:text-fg' }}">
                {{ $label }}
            </button>
        @endforeach
    </nav>

    {{-- ============================================================ FIND SUPPLIERS --}}
    @if ($tab === 'find')
        @if ($supplier && isset($supplierProfile))
            <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <button type="button" wire:click="closeSupplier" class="text-sm text-muted hover:text-fg">← All suppliers</button>
                    <h2 class="mt-1 text-lg font-bold">{{ $supplierProfile->business_name }}</h2>
                    @if ($supplierProfile->supply_note)<p class="mt-1 max-w-2xl text-sm text-muted">{{ $supplierProfile->supply_note }}</p>@endif
                </div>
                @if ($connection?->isApproved())
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="pill !bg-emerald-500/10 !text-emerald-600">Connected · you earn {{ rtrim(rtrim(number_format($connection->margin_pct, 2), '0'), '.') }}%</span>
                        <button type="button" wire:click="addAll" wire:loading.attr="disabled" class="btn-ghost btn-sm">Add all on this page</button>
                        @if ($missingCount > 0)
                            <button type="button" wire:click="addCatalog" wire:loading.attr="disabled" wire:confirm="Add all {{ $missingCount }} products this supplier offers to your store?" class="btn-accent btn-sm">Add whole catalog ({{ $missingCount }})</button>
                        @endif
                    </div>
                @endif
            </div>

            @unless ($connection?->isApproved())
                <div class="card mt-4 p-5">
                    @if ($connection?->status === 'pending')
                        <p class="text-sm"><x-ui.icon name="clock" class="mr-1 inline w-4 h-4" /> Your request is waiting for {{ $supplierProfile->business_name }} to answer. You'll get a notification.</p>
                    @else
                        <p class="text-sm font-semibold">Ask to resell {{ $supplierProfile->business_name }}'s products</p>
                        <p class="mt-1 text-sm text-muted">They offer a {{ rtrim(rtrim(number_format($supplierProfile->supply_margin_pct, 2), '0'), '.') }}% margin{{ $supplierProfile->supply_auto_approve ? ' and approve requests straight away' : '' }}.</p>
                        <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                            <input wire:model="requestMessage" maxlength="500" class="input flex-1" placeholder="Optional note: tell them about your store">
                            <button type="button" wire:click="requestConnection({{ $supplier }})" class="btn-accent">Request to resell</button>
                        </div>
                        @error('supplier') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                    @endif
                </div>
            @endunless

            <div class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                <input wire:model.live.debounce.400ms="q" class="input" placeholder="Search this supplier's parts" aria-label="Search this supplier's parts">
                <select wire:model.live="category" class="input" aria-label="Category">
                    <option value="">All categories</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->parent_id ? '— ' : '' }}{{ $cat->name }}</option>
                    @endforeach
                </select>
                <select wire:model.live="make" class="input" aria-label="Make">
                    <option value="">Any make</option>
                    @foreach ($makes as $mk)
                        <option value="{{ $mk->id }}">{{ $mk->name }}</option>
                    @endforeach
                </select>
                <select wire:model.live="model" class="input" aria-label="Model" @disabled(! $make)>
                    <option value="">{{ $make ? 'Any model' : 'Pick a make first' }}</option>
                    @foreach ($models as $md)
                        <option value="{{ $md->id }}">{{ $md->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @forelse ($catalog as $p)
                    @php($earn = $connection?->isApproved() ? round($p->effectivePrice() * $connection->margin_pct / 100, 2) : null)
                    <div class="card flex gap-3 p-3" wire:key="sp-{{ $p->id }}">
                        <img src="{{ $p->getFirstMediaUrl('images', 'thumb') ?: $p->getFirstMediaUrl('images') }}" alt="" class="h-16 w-16 shrink-0 rounded-lg bg-surface-2 object-cover">
                        <div class="min-w-0 flex-1">
                            <p class="line-clamp-2 text-sm font-medium">{{ $p->title }}</p>
                            <p class="mt-0.5 text-xs text-muted">{{ $p->category?->name }} · {{ $p->quantity }} in stock</p>
                            <p class="mt-1 text-sm"><span class="font-bold">${{ number_format($p->effectivePrice(), 2) }}</span>
                                @if ($earn !== null)<span class="text-xs text-emerald-600"> · you earn ${{ number_format($earn, 2) }}</span>@endif
                            </p>
                            @if ($connection?->isApproved())
                                @if (in_array($p->id, $added, true))
                                    <span class="mt-2 inline-flex items-center gap-1 text-xs font-medium text-emerald-600"><x-ui.icon name="check" class="w-3.5 h-3.5" /> In your store</span>
                                @else
                                    <button type="button" wire:click="add({{ $p->id }})" wire:loading.attr="disabled" class="btn-ghost btn-sm mt-2 !px-2">+ Add to my store</button>
                                @endif
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-muted">No products to show.</p>
                @endforelse
            </div>
            <div class="mt-4">{{ $catalog->links() }}</div>
        @else
            <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
                <input wire:model.live.debounce.400ms="q" class="input max-w-md" placeholder="Search suppliers" aria-label="Search suppliers">
            </div>
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @forelse ($suppliers as $s)
                    @php($c = $myConnections[$s->user_id] ?? null)
                    <button type="button" wire:click="openSupplier({{ $s->user_id }})" wire:key="sup-{{ $s->user_id }}" class="card flex items-center gap-3 p-4 text-left transition hover:shadow-card-hover">
                        <img src="{{ $s->logoUrl() ?? '' }}" alt="" class="h-12 w-12 shrink-0 rounded-xl bg-surface-2 object-cover">
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-semibold">{{ $s->business_name }}</p>
                            <p class="text-xs text-muted">{{ $supplierCounts[$s->user_id] ?? 0 }} parts · {{ rtrim(rtrim(number_format($s->supply_margin_pct, 2), '0'), '.') }}% margin</p>
                            @if ($c)
                                <span class="mt-1 inline-block text-xs font-medium {{ $c->status === 'approved' ? 'text-emerald-600' : 'text-muted' }}">{{ ['approved' => 'Connected', 'pending' => 'Request sent', 'declined' => 'Declined', 'ended' => 'Ended'][$c->status] }}</span>
                            @endif
                        </div>
                        <x-ui.icon name="chevron-right" class="w-4 h-4 text-muted" />
                    </button>
                @empty
                    <p class="text-sm text-muted sm:col-span-3">No stores are offering their products for resale yet.</p>
                @endforelse
            </div>
            <div class="mt-4">{{ $suppliers->links() }}</div>
        @endif
    @endif

    {{-- ============================================================ MY RESOLD LISTINGS --}}
    @if ($tab === 'listings')
        <div class="card mt-6 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-surface-2 text-left text-xs uppercase tracking-wide text-muted">
                        <tr><th class="px-4 py-3">Listing</th><th class="px-4 py-3">Supplier</th><th class="px-4 py-3">Price</th><th class="px-4 py-3">You earn</th><th class="px-4 py-3">Status</th><th class="px-4 py-3"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($listings as $l)
                            @php($pct = (float) ($marginBySupplier[$l->source?->seller_id] ?? 0))
                            @php($live = $l->isListed())
                            <tr wire:key="rl-{{ $l->id }}">
                                <td class="px-4 py-3"><a href="{{ route('products.show', $l->slug) }}" target="_blank" class="line-clamp-1 font-medium hover:text-accent">{{ $l->title }}</a></td>
                                <td class="px-4 py-3 text-muted">{{ $l->source?->seller?->sellerProfile?->business_name ?? '—' }}</td>
                                <td class="px-4 py-3">${{ number_format($l->effectivePrice(), 2) }}</td>
                                <td class="px-4 py-3 text-emerald-600">{{ $pct ? '$'.number_format($l->effectivePrice() * $pct / 100, 2) : '—' }}</td>
                                <td class="px-4 py-3">
                                    @if ($l->status !== 'active')
                                        <span class="pill">Paused</span>
                                    @elseif ($live)
                                        <span class="pill !bg-emerald-500/10 !text-emerald-600">For sale</span>
                                    @else
                                        <span class="pill !bg-amber-500/10 !text-amber-600" title="The supplier's product is unavailable or your partnership ended">Unavailable</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <button type="button" wire:click="toggleListing({{ $l->id }})" class="text-xs font-medium text-muted hover:text-fg">{{ $l->status === 'active' ? 'Pause' : 'Resume' }}</button>
                                    <button type="button" wire:click="removeListing({{ $l->id }})" wire:confirm="Remove this listing from your store?" class="ml-3 text-xs font-medium text-muted hover:text-rose-500">Remove</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-10 text-center text-muted">You don't resell anything yet. <button type="button" wire:click="$set('tab', 'find')" class="font-medium text-accent hover:underline">Find suppliers</button></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="mt-4">{{ $listings->links() }}</div>
        <p class="mt-3 text-xs text-muted">Price, stock, photos, fitment and options come from the supplier and update by themselves.</p>
    @endif

    {{-- ============================================================ RESALE SALES --}}
    @if ($tab === 'sales')
        <div class="mt-6 grid gap-3 sm:grid-cols-3">
            <div class="card p-4"><p class="text-xs text-muted">Margin paid to you</p><p class="mt-1 text-2xl font-bold">${{ number_format($totals['earned'], 2) }}</p></div>
            <div class="card p-4"><p class="text-xs text-muted">Margin on the way</p><p class="mt-1 text-2xl font-bold">${{ number_format($totals['pending'], 2) }}</p><p class="text-xs text-muted">Paid after delivery</p></div>
            <div class="card p-4"><p class="text-xs text-muted">Resale orders</p><p class="mt-1 text-2xl font-bold">{{ $totals['orders'] }}</p></div>
        </div>
        <div class="card mt-4 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-surface-2 text-left text-xs uppercase tracking-wide text-muted">
                        <tr><th class="px-4 py-3">Order</th><th class="px-4 py-3">Item</th><th class="px-4 py-3">Ships from</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Sale</th><th class="px-4 py-3">Your margin</th></tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($sales as $s)
                            <tr wire:key="rs-{{ $s->id }}">
                                <td class="px-4 py-3 font-mono text-xs">{{ $s->order?->order_number }}<br><span class="font-sans text-muted">{{ $s->created_at->format('M j, Y') }}</span></td>
                                <td class="px-4 py-3"><span class="line-clamp-2">{{ $s->product_title }}</span>@if ($s->tracking_number)<span class="text-xs text-muted">{{ strtoupper((string) $s->carrier) }} {{ $s->tracking_number }}</span>@endif</td>
                                <td class="px-4 py-3 text-muted">{{ $s->seller?->sellerProfile?->business_name }}</td>
                                <td class="px-4 py-3"><span class="pill">{{ $s->status->label() }}</span></td>
                                <td class="px-4 py-3">${{ number_format($s->line_total, 2) }}</td>
                                <td class="px-4 py-3 {{ in_array($s->status->value, ['cancelled', 'refunded'], true) ? 'text-muted line-through' : 'text-emerald-600' }}">
                                    ${{ number_format($s->reseller_margin, 2) }}
                                    <span class="block text-xs text-muted">{{ $s->reseller_paid_at ? 'Paid '.$s->reseller_paid_at->format('M j') : (in_array($s->status->value, ['cancelled', 'refunded'], true) ? 'Not earned' : 'After delivery') }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-10 text-center text-muted">No resale orders yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="mt-4">{{ $sales->links() }}</div>
    @endif

    {{-- ============================================================ OFFER MY PRODUCTS --}}
    @if ($tab === 'offer')
        <form wire:submit="saveSettings" class="card mt-6 space-y-4 p-6">
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model.live="enabled" class="mt-1 rounded text-accent focus:ring-accent">
                <span><span class="font-semibold">Let other stores resell my products</span><br><span class="text-sm text-muted">They list your parts in their store at your price. You ship the orders and get paid as usual, minus their margin.</span></span>
            </label>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="sn-margin" class="label">Reseller margin (%)</label>
                    <input id="sn-margin" type="number" step="0.5" min="{{ \App\Services\Seller\SupplyNetworkService::MIN_MARGIN }}" max="{{ \App\Services\Seller\SupplyNetworkService::MAX_MARGIN }}" wire:model="margin" class="input">
                    <p class="mt-1 text-xs text-muted">What a reseller earns from each sale. You can change it per reseller later. Minimum {{ (int) \App\Services\Seller\SupplyNetworkService::MIN_MARGIN }}%.</p>
                    @error('margin') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
                <label class="flex items-start gap-3 pt-6">
                    <input type="checkbox" wire:model="autoApprove" class="mt-1 rounded text-accent focus:ring-accent">
                    <span class="text-sm"><span class="font-medium">Approve resellers automatically</span><br><span class="text-muted">Otherwise you approve each request.</span></span>
                </label>
            </div>
            <div>
                <label for="sn-note" class="label">Note for resellers (optional)</label>
                <textarea id="sn-note" wire:model="note" rows="2" maxlength="500" class="input" placeholder="What you sell, how fast you ship, anything resellers should know"></textarea>
            </div>
            <button class="btn-accent">Save</button>
        </form>

        @if ($enabled)
            <div class="card mt-4 p-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 class="font-bold">Products offered</h2>
                        <p class="text-sm text-muted">{{ $offeredCount }} of your live products are offered. Untick any you want to keep to your own store.</p>
                    </div>
                    <input wire:model.live.debounce.400ms="excludeSearch" class="input max-w-xs" placeholder="Search your products" aria-label="Search your products">
                </div>
                <ul class="mt-4 divide-y divide-line">
                    @foreach ($products as $p)
                        <li class="flex items-center justify-between gap-3 py-2" wire:key="ex-{{ $p->id }}">
                            <span class="min-w-0">
                                <span class="line-clamp-1 text-sm">{{ $p->title }}</span>
                                @if (($resoldBy[$p->id] ?? 0) > 0)<span class="text-xs text-muted">Resold by {{ $resoldBy[$p->id] }} {{ Str::plural('store', $resoldBy[$p->id]) }}</span>@endif
                            </span>
                            <label class="flex shrink-0 items-center gap-2 text-sm">
                                <input type="checkbox" @checked(! $p->supply_excluded) wire:click="toggleExcluded({{ $p->id }})" class="rounded text-accent focus:ring-accent">
                                Offered
                            </label>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-3">{{ $products->links() }}</div>
            </div>
        @endif
    @endif

    {{-- ============================================================ MY RESELLERS --}}
    @if ($tab === 'resellers')
        <div class="mt-6 space-y-3">
            @forelse ($connections as $c)
                @php($st = $resellerStats[$c->reseller_id] ?? null)
                <div class="card flex flex-wrap items-center gap-4 p-4" wire:key="rc-{{ $c->id }}">
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold">{{ $c->reseller?->sellerProfile?->business_name ?? 'Store' }}
                            <span class="pill ml-1 {{ $c->status === 'approved' ? '!bg-emerald-500/10 !text-emerald-600' : ($c->status === 'pending' ? '!bg-amber-500/10 !text-amber-600' : '') }}">{{ ucfirst($c->status) }}</span>
                        </p>
                        @if ($c->message)<p class="mt-1 text-sm text-muted">"{{ $c->message }}"</p>@endif
                        @if ($st)<p class="mt-1 text-xs text-muted">{{ $st->orders }} orders · ${{ number_format($st->sales, 2) }} sales · ${{ number_format($st->margin, 2) }} margin</p>@endif
                    </div>
                    @if (in_array($c->status, ['pending', 'approved'], true))
                        <div class="flex items-center gap-2">
                            <label class="sr-only" for="m-{{ $c->id }}">Margin %</label>
                            <input id="m-{{ $c->id }}" type="number" step="0.5" wire:model="margins.{{ $c->id }}" class="input h-9 w-20 text-sm">
                            <span class="text-sm text-muted">%</span>
                        </div>
                    @endif
                    <div class="flex items-center gap-2">
                        @if ($c->status === 'pending')
                            <button type="button" wire:click="approve({{ $c->id }})" class="btn-accent btn-sm">Approve</button>
                            <button type="button" wire:click="decline({{ $c->id }})" class="btn-ghost btn-sm">Decline</button>
                        @elseif ($c->status === 'approved')
                            <button type="button" wire:click="saveMargin({{ $c->id }})" class="btn-ghost btn-sm">Save margin</button>
                            <button type="button" wire:click="endConnection({{ $c->id }})" wire:confirm="End this partnership? Their copies of your products stop being for sale." class="btn-ghost btn-sm hover:!text-rose-500">End</button>
                        @endif
                    </div>
                </div>
            @empty
                <div class="card p-10 text-center text-sm text-muted">
                    No resellers yet.
                    @unless ($me->sellerProfile->supply_enabled)
                        <button type="button" wire:click="$set('tab', 'offer')" class="font-medium text-accent hover:underline">Offer your products</button> to get started.
                    @endunless
                </div>
            @endforelse
        </div>
    @endif
</div>
