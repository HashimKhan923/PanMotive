<div>
    <h1 class="text-2xl font-bold">Subscriptions</h1>
    <p class="mt-1 text-sm text-muted">Buyers on Subscribe &amp; Save get your parts on repeat. Turn it on or set the discount per listing in the product editor.</p>

    <div class="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="card p-4">
            <p class="text-xs uppercase tracking-wide text-muted">Active subscribers</p>
            <p class="mt-1 text-2xl font-bold">{{ $counts['active'] }}</p>
            <p class="text-xs text-muted">{{ $counts['paused'] }} paused</p>
        </div>
        <div class="card p-4">
            <p class="text-xs uppercase tracking-wide text-muted">Recurring revenue</p>
            <p class="mt-1 text-2xl font-bold">${{ number_format($mrr, 2) }}</p>
            <p class="text-xs text-muted">estimated per month</p>
        </div>
        <div class="card p-4">
            <p class="text-xs uppercase tracking-wide text-muted">Due in 14 days</p>
            <p class="mt-1 text-2xl font-bold">{{ $forecast->sum('deliveries') }}</p>
            <p class="text-xs text-muted">{{ $forecast->sum('needed') }} units to ship</p>
        </div>
        <div class="card p-4">
            <p class="text-xs uppercase tracking-wide text-muted">Cancelled (30 days)</p>
            <p class="mt-1 text-2xl font-bold">{{ $churn30 }}</p>
            <p class="text-xs text-muted">{{ $counts['cancelled'] }} all-time</p>
        </div>
    </div>

    @if ($forecast->isNotEmpty())
        <section class="card mt-6 p-6">
            <h2 class="font-bold">Upcoming repeat orders</h2>
            <p class="mt-1 text-sm text-muted">Stock you'll need over the next two weeks. A shortfall means a subscriber's order can't be filled on time.</p>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[560px] text-sm">
                    <thead class="text-left text-xs uppercase text-muted">
                        <tr><th class="py-2">Part</th><th class="py-2">First due</th><th class="py-2 text-right">Orders</th><th class="py-2 text-right">Units needed</th><th class="py-2 text-right">On hand</th><th class="py-2"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($forecast as $f)
                            <tr>
                                <td class="py-2 font-medium">{{ $f['title'] }}</td>
                                <td class="py-2 text-muted">{{ $f['next']?->format('M j') }}</td>
                                <td class="py-2 text-right">{{ $f['deliveries'] }}</td>
                                <td class="py-2 text-right">{{ $f['needed'] }}</td>
                                <td class="py-2 text-right {{ $f['short'] > 0 ? 'font-semibold text-rose-600' : '' }}">{{ $f['on_hand'] }}</td>
                                <td class="py-2 text-right">
                                    @if ($f['short'] > 0)
                                        <a href="{{ route('seller.inventory.index') }}" class="rounded bg-rose-500/10 px-2 py-0.5 text-xs font-semibold text-rose-600 hover:underline">Short {{ $f['short'] }} — restock</a>
                                    @else
                                        <span class="text-xs text-emerald-600">Covered</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <div class="mt-6 flex gap-1 border-b border-line text-sm">
        @foreach (['active' => 'Active', 'paused' => 'Paused', 'cancelled' => 'Cancelled', 'all' => 'All'] as $key => $label)
            <button wire:click="$set('status', '{{ $key }}')"
                    class="-mb-px border-b-2 px-3 py-2 font-medium transition {{ $status === $key ? 'border-accent text-fg' : 'border-transparent text-muted hover:text-fg' }}">
                {{ $label }} @if (isset($counts[$key]) && $counts[$key] > 0)<span class="ml-1 rounded-full bg-surface-2 px-1.5 text-xs">{{ $counts[$key] }}</span>@endif
            </button>
        @endforeach
    </div>

    <div class="mt-4 overflow-x-auto rounded-xl border border-line">
        <table class="w-full min-w-[760px] text-sm">
            <thead class="bg-surface-2 text-left text-xs uppercase text-muted">
                <tr>
                    <th class="px-4 py-3">Subscriber</th>
                    <th class="px-4 py-3">Part</th>
                    <th class="px-4 py-3">Schedule</th>
                    <th class="px-4 py-3 text-right">Per delivery</th>
                    <th class="px-4 py-3">Next delivery</th>
                    <th class="px-4 py-3 text-right">Orders</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($subscriptions as $s)
                    <tr class="bg-surface" wire:key="sub-{{ $s->id }}">
                        <td class="px-4 py-3">
                            @if ($s->buyer)
                                <a href="{{ route('seller.customers.show', $s->buyer) }}" class="font-medium hover:text-accent">{{ $s->buyer->name }}</a>
                            @else
                                <span class="text-muted">Deleted account</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            {{ $s->product?->title ?? 'Listing removed' }}
                            @if ($s->variant)<p class="text-xs text-muted">{{ $s->variant->label }}</p>@endif
                        </td>
                        <td class="px-4 py-3">{{ $s->quantity }} × {{ strtolower($s->intervalLabel()) }}<p class="text-xs text-muted">{{ rtrim(rtrim(number_format((float) $s->discount_pct, 2), '0'), '.') }}% off</p></td>
                        <td class="px-4 py-3 text-right font-semibold">${{ number_format($s->unitPrice() * $s->quantity, 2) }}</td>
                        <td class="px-4 py-3">
                            @if ($s->status === 'active' && $s->next_run_at)
                                {{ $s->next_run_at->format('M j, Y') }}
                            @else
                                <span class="pill capitalize">{{ $s->status }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">{{ $s->orders_count }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-16 text-center">
                        <x-ui.icon name="refresh" class="mx-auto w-12 h-12 text-muted" />
                        <p class="mt-3 font-semibold">No {{ $status === 'all' ? '' : $status }} subscriptions</p>
                        <p class="mt-1 text-sm text-muted">Buyers can subscribe from any listing with Subscribe &amp; Save turned on.</p>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $subscriptions->links() }}</div>
</div>
