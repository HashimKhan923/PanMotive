@extends('layouts.seller')

@section('title', 'Orders')

@section('content')
<div>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">Orders</h1>
            <p class="mt-1 text-sm text-muted">Accept new orders, then ship with tracking. Payout releases when the buyer confirms delivery.</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('seller.orders.export', request()->only('status', 'q', 'from', 'to')) }}" class="btn-outline btn-sm"><x-ui.icon name="download" class="w-4 h-4" /> Export CSV</a>
            <a href="{{ route('seller.fulfilment.pick-list') }}" target="_blank" class="btn-outline btn-sm"><x-ui.icon name="list" class="w-4 h-4" /> Pick list</a>
        </div>
    </div>

    @if ($errors->has('cancel'))
        <div class="mt-4 rounded-xl border border-rose-500/40 bg-rose-500/10 px-4 py-3 text-sm text-rose-700 dark:text-rose-400">{{ $errors->first('cancel') }}</div>
    @endif

    {{-- tabs --}}
    <div class="mt-6 flex flex-wrap gap-1 overflow-x-auto border-b border-line text-sm">
        <a href="{{ route('seller.orders.index') }}"
           class="-mb-px whitespace-nowrap border-b-2 px-3 py-2 font-medium transition {{ $status === '' ? 'border-accent text-accent' : 'border-transparent text-muted hover:text-fg' }}">
            All @if ($counts['all'] > 0)<span class="ml-1 rounded-full bg-surface-2 px-1.5 text-xs">{{ $counts['all'] }}</span>@endif
        </a>
        @foreach ($tabs as $key => [$label])
            @continue($key === 'unpaid' && $counts['unpaid'] === 0)
            <a href="{{ route('seller.orders.index', ['status' => $key]) }}"
               class="-mb-px whitespace-nowrap border-b-2 px-3 py-2 font-medium transition
                      {{ $status === $key ? 'border-accent text-accent' : 'border-transparent text-muted hover:text-fg' }}">
                {{ $label }}
                @if (($counts[$key] ?? 0) > 0)
                    <span class="ml-1 rounded-full px-1.5 text-xs {{ $key === 'new' ? 'bg-accent text-accent-fg' : 'bg-surface-2' }}">{{ $counts[$key] }}</span>
                @endif
            </a>
        @endforeach
        @if ($counts['late'] > 0 || $status === 'late')
            <a href="{{ route('seller.orders.index', ['status' => 'late']) }}"
               class="-mb-px whitespace-nowrap border-b-2 px-3 py-2 font-medium transition {{ $status === 'late' ? 'border-rose-500 text-rose-600' : 'border-transparent text-rose-600 hover:text-rose-700' }}">
                Late to ship <span class="ml-1 rounded-full bg-rose-500 px-1.5 text-xs text-white">{{ $counts['late'] }}</span>
            </a>
        @endif
    </div>

    <form method="GET" class="mt-4 flex flex-wrap items-end gap-2">
        <input type="hidden" name="status" value="{{ $status }}">
        <div class="relative w-full sm:w-80">
            <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 w-4 h-4 -translate-y-1/2 text-muted" />
            <input name="q" value="{{ request('q') }}" placeholder="Order #, buyer, product, SKU or tracking" class="input h-10 pl-9 text-sm">
        </div>
        <div>
            <label class="sr-only" for="of">From</label>
            <input id="of" type="date" name="from" value="{{ request('from') }}" class="input h-10 text-sm" title="From">
        </div>
        <div>
            <label class="sr-only" for="ot">To</label>
            <input id="ot" type="date" name="to" value="{{ request('to') }}" class="input h-10 text-sm" title="To">
        </div>
        <button class="btn-outline btn-sm h-10">Filter</button>
        @if (request()->hasAny(['q', 'from', 'to']))
            <a href="{{ route('seller.orders.index', array_filter(['status' => $status])) }}" class="btn-ghost btn-sm h-10">Clear</a>
        @endif
    </form>

    @if ($status === 'late')
        <p class="mt-3 rounded-xl border border-rose-500/30 bg-rose-500/5 px-4 py-3 text-sm text-rose-700 dark:text-rose-400">These are past the handling time you promised buyers. Ship them as soon as you can — late shipments lower your on-time rate and seller tier.</p>
    @endif

    @if ($status === 'new' && $counts['new'] > 0)
        <form id="bulk-accept" method="POST" action="{{ route('seller.orders.bulk-accept') }}" class="mt-3 flex items-center gap-3"
              x-data="{ n: 0 }" @change.window="n = document.querySelectorAll('.bulk-accept-box:checked').length">
            @csrf
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" class="rounded border-line text-accent"
                       @change="document.querySelectorAll('.bulk-accept-box').forEach(c => c.checked = $event.target.checked); n = document.querySelectorAll('.bulk-accept-box:checked').length">
                Select all on this page
            </label>
            <button class="btn-accent btn-sm" :disabled="n === 0" x-text="n ? 'Accept ' + n + ' selected' : 'Accept selected'">Accept selected</button>
        </form>
    @endif

    @if ($status === 'unpaid')
        <p class="mt-3 rounded-xl bg-surface-2 px-4 py-3 text-sm text-muted">These buyers haven't completed payment yet. You'll be notified the moment it clears — there's nothing to do until then.</p>
    @endif

    <div class="mt-4 overflow-x-auto rounded-xl border border-line">
        <table class="w-full min-w-[820px] text-sm">
            <thead class="bg-surface-2 text-left text-xs uppercase text-muted">
                <tr>
                    @if ($status === 'new')<th class="w-8 px-4 py-3"></th>@endif
                    <th class="px-4 py-3">Order</th>
                    <th class="px-4 py-3">Buyer</th>
                    <th class="px-4 py-3">Product</th>
                    <th class="px-4 py-3">Qty</th>
                    <th class="px-4 py-3">Payout</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Ship by</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($items as $item)
                    @php($s = $item->status->value)
                    <tr class="{{ $item->isLate() ? 'bg-rose-500/5' : 'bg-surface' }}">
                        @if ($status === 'new')
                            <td class="px-4 py-3">@if ($s === 'paid')<input type="checkbox" form="bulk-accept" name="ids[]" value="{{ $item->id }}" class="bulk-accept-box rounded border-line text-accent" aria-label="Select order">@endif</td>
                        @endif
                        <td class="px-4 py-3">
                            <a href="{{ route('seller.orders.show', $item) }}" class="font-medium hover:text-accent">{{ $item->order->order_number }}</a>
                            <p class="text-xs text-muted">{{ $item->created_at->format('M j, Y') }}</p>
                        </td>
                        <td class="px-4 py-3 text-muted">{{ $item->order->contactName() ?: '—' }}</td>
                        <td class="px-4 py-3">
                            {{ $item->product_title }}
                            @if ($item->variant_label)<p class="text-xs text-muted">{{ $item->variant_label }}</p>@endif
                        </td>
                        <td class="px-4 py-3">{{ $item->quantity }}</td>
                        <td class="px-4 py-3 font-semibold {{ in_array($s, ['cancelled', 'refunded']) ? 'text-muted line-through' : '' }}">${{ number_format($item->seller_payout_amount, 2) }}</td>
                        <td class="px-4 py-3">
                            <span class="pill {{ match ($s) { 'paid' => '!bg-emerald-500/10 !text-emerald-600', 'cancelled', 'refunded' => '!bg-rose-500/10 !text-rose-600', 'return_requested', 'disputed' => '!bg-amber-500/15 !text-amber-700 dark:!text-amber-400', default => '' } }}">
                                {{ $s === 'paid' ? 'New — to accept' : $item->status->label() }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-xs">
                            @if ($shipBy = $item->shipBy())
                                <span class="{{ $item->isLate() ? 'font-semibold text-rose-600' : ($shipBy->isToday() ? 'font-semibold text-amber-600' : 'text-muted') }}">
                                    {{ $item->isLate() ? 'Late — was '.$shipBy->format('M j') : ($shipBy->isToday() ? 'Today' : $shipBy->format('D, M j')) }}
                                </span>
                            @elseif ($item->trackingUrl())
                                <a href="{{ $item->trackingUrl() }}" target="_blank" rel="noopener" class="text-accent hover:underline">Track</a>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            @if ($s === 'paid')
                                <form method="POST" action="{{ route('seller.orders.accept', $item) }}" class="inline">
                                    @csrf
                                    <button class="btn-accent btn-sm">Accept</button>
                                </form>
                            @elseif ($s === 'seller_accepted')
                                <button onclick="document.getElementById('ship-{{ $item->id }}').showModal()" class="btn-accent btn-sm">Mark shipped</button>
                                <dialog id="ship-{{ $item->id }}" class="card max-w-sm p-6 text-left backdrop:bg-black/50">
                                    <form method="POST" action="{{ route('seller.orders.ship', $item) }}" class="space-y-3">
                                        @csrf
                                        <h3 class="font-semibold">Ship order {{ $item->order->order_number }}</h3>
                                        <div>
                                            <label class="label" for="c-{{ $item->id }}">Carrier</label>
                                            <select id="c-{{ $item->id }}" name="carrier" class="input" required>
                                                <option value="USPS">USPS</option>
                                                <option value="UPS">UPS</option>
                                                <option value="FedEx">FedEx</option>
                                                <option value="DHL">DHL</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="label" for="t-{{ $item->id }}">Tracking number</label>
                                            <input id="t-{{ $item->id }}" name="tracking_number" class="input" required>
                                        </div>
                                        <div class="flex gap-2 pt-1">
                                            <button class="btn-accent btn-sm flex-1">Confirm shipment</button>
                                            <button type="button" onclick="this.closest('dialog').close()" class="btn-ghost btn-sm">Close</button>
                                        </div>
                                    </form>
                                </dialog>
                            @else
                                <a href="{{ route('seller.orders.show', $item) }}" class="text-sm font-medium text-accent hover:underline">View</a>
                            @endif

                            @if (in_array($s, ['placed', 'paid', 'seller_accepted']) && \App\Support\SellerContext::can('orders.manage'))
                                <button type="button" onclick="document.getElementById('cancel-{{ $item->id }}').showModal()"
                                        class="btn-ghost btn-sm !px-2 text-muted hover:text-rose-600" title="Cancel order">
                                    <x-ui.icon name="x" class="w-4 h-4" />
                                </button>
                                @include('seller.orders.partials.cancel-dialog', ['item' => $item, 'siblingCount' => 0])
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-16 text-center">
                        <x-ui.icon name="truck" class="mx-auto w-10 h-10 text-muted" />
                        <p class="mt-3 font-semibold">No orders here</p>
                        <p class="mt-1 text-sm text-muted">{{ request()->hasAny(['q', 'from', 'to']) ? 'Nothing matches those filters.' : 'New orders will show up as buyers check out.' }}</p>
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $items->links() }}</div>
</div>
@endsection
