<div>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">Customers</h1>
            <p class="mt-1 text-sm text-muted">Everyone who's bought from your store — what they spend, how often they come back, and your private notes on them.</p>
        </div>
        <button wire:click="export" class="btn-outline btn-sm"><x-ui.icon name="download" class="w-4 h-4" /> Export CSV</button>
    </div>

    {{-- KPIs --}}
    <div class="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="card p-4">
            <p class="text-xs uppercase tracking-wide text-muted">Customers</p>
            <p class="mt-1 text-2xl font-bold">{{ number_format($summary['customers']) }}</p>
            <p class="text-xs text-muted">+{{ $summary['new_30d'] }} in the last 30 days</p>
        </div>
        <div class="card p-4">
            <p class="text-xs uppercase tracking-wide text-muted">Repeat rate</p>
            <p class="mt-1 text-2xl font-bold">{{ $summary['repeat_rate'] }}%</p>
            <p class="text-xs text-muted">bought from you 2+ times</p>
        </div>
        <div class="card p-4">
            <p class="text-xs uppercase tracking-wide text-muted">Avg lifetime value</p>
            <p class="mt-1 text-2xl font-bold">${{ number_format($summary['avg_ltv'], 2) }}</p>
            <p class="text-xs text-muted">per customer</p>
        </div>
        <div class="card p-4">
            <p class="text-xs uppercase tracking-wide text-muted">Guest orders</p>
            <p class="mt-1 text-2xl font-bold">{{ number_format($summary['guest_orders']) }}</p>
            <p class="text-xs text-muted">checked out without an account</p>
        </div>
    </div>

    {{-- segments --}}
    <div class="mt-6 flex flex-wrap gap-1 border-b border-line text-sm">
        <button wire:click="$set('segment', '')"
                class="-mb-px border-b-2 px-3 py-2 font-medium transition {{ $segment === '' ? 'border-accent text-fg' : 'border-transparent text-muted hover:text-fg' }}">All</button>
        @foreach ($segments as $key => $label)
            <button wire:click="$set('segment', '{{ $key }}')"
                    class="-mb-px whitespace-nowrap border-b-2 px-3 py-2 font-medium transition {{ $segment === $key ? 'border-accent text-fg' : 'border-transparent text-muted hover:text-fg' }}">{{ $label }}</button>
        @endforeach
    </div>

    <div class="mt-4 flex flex-wrap items-end gap-2">
        <div class="relative w-full sm:w-72">
            <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 w-4 h-4 -translate-y-1/2 text-muted" />
            <input wire:model.live.debounce.300ms="search" placeholder="Search by name or email" class="input h-10 pl-9 text-sm" aria-label="Search customers">
        </div>
        @if ($allTags->isNotEmpty())
            <select wire:model.live="tag" class="input h-10 w-44 text-sm" aria-label="Filter by tag">
                <option value="">All tags</option>
                @foreach ($allTags as $t)
                    <option value="{{ $t }}">#{{ $t }}</option>
                @endforeach
            </select>
        @endif
        <select wire:model.live="sort" class="input h-10 w-48 text-sm" aria-label="Sort">
            <option value="last_order">Most recent order</option>
            <option value="spent">Highest spend</option>
            <option value="orders">Most orders</option>
            <option value="first_order">Longest customer</option>
            <option value="name">Name A–Z</option>
        </select>
    </div>

    <div class="mt-4 overflow-x-auto rounded-xl border border-line">
        <table class="w-full min-w-[900px] text-sm">
            <thead class="bg-surface-2 text-left text-xs uppercase text-muted">
                <tr>
                    <th class="px-4 py-3">Customer</th>
                    <th class="px-4 py-3">Location</th>
                    <th class="px-4 py-3 text-right">Orders</th>
                    <th class="px-4 py-3 text-right">Lifetime spend</th>
                    <th class="px-4 py-3 text-right">Avg order</th>
                    <th class="px-4 py-3">Last order</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($customers as $c)
                    @php($tags = $notes[$c->id]->tags ?? [])
                    <tr class="bg-surface" wire:key="cust-{{ $c->id }}">
                        <td class="px-4 py-3">
                            <a href="{{ route('seller.customers.show', $c->id) }}" class="flex items-center gap-3">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-surface-2 text-sm font-bold text-fg">{{ strtoupper(mb_substr($c->name, 0, 1)) }}</span>
                                <span>
                                    <span class="font-medium hover:text-accent">{{ $c->name }}</span>
                                    <span class="mt-0.5 flex flex-wrap gap-1">
                                        @foreach ($insights->badges($c) as $b)
                                            <span class="rounded px-1.5 text-[10px] font-semibold uppercase {{ $b === 'VIP' ? 'bg-accent text-accent-fg' : ($b === 'Lapsed' ? 'bg-rose-500/10 text-rose-600' : 'bg-surface-2 text-muted') }}">{{ $b }}</span>
                                        @endforeach
                                        @foreach ($tags as $t)
                                            <span class="rounded bg-sky-500/10 px-1.5 text-[10px] font-semibold text-sky-600">#{{ $t }}</span>
                                        @endforeach
                                    </span>
                                </span>
                            </a>
                        </td>
                        <td class="px-4 py-3">
                            @if ($loc = $locations[$c->id] ?? null)
                                <span class="inline-flex items-center gap-2 whitespace-nowrap">{{ $loc['text'] }} <x-country-flag :code="$loc['country']" /></span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">{{ $c->orders_count }}</td>
                        <td class="px-4 py-3 text-right font-semibold">${{ number_format((float) $c->spent, 2) }}</td>
                        <td class="px-4 py-3 text-right text-muted">${{ number_format((float) $c->spent / max(1, (int) $c->orders_count), 2) }}</td>
                        <td class="px-4 py-3 text-muted">{{ \Illuminate\Support\Carbon::parse($c->last_order_at)->diffForHumans() }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('seller.customers.show', $c->id) }}" class="text-sm font-medium text-accent hover:underline">View</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-16 text-center">
                        <x-ui.icon name="users" class="mx-auto w-12 h-12 text-muted" />
                        <p class="mt-3 font-semibold">{{ $search || $segment || $tag ? 'No customers match' : 'No customers yet' }}</p>
                        <p class="mt-1 text-sm text-muted">{{ $search || $segment || $tag ? 'Try a different search or segment.' : 'Buyers appear here after their first paid order with you.' }}</p>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $customers->links() }}</div>
</div>
