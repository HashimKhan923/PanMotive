@extends('layouts.seller')

@section('title', 'Payouts')

@section('content')
<div>
    <h1 class="text-2xl font-bold">Payouts</h1>
    <p class="mt-1 text-sm text-muted">Each order's money is held safely until the buyer confirms delivery (or {{ $autoDays }} days after shipping), then sent to your bank through Stripe. Refunds after payout are taken back from your next transfers.</p>

    {{-- what's in the way --}}
    @forelse ($blockers as $b)
        <div class="mt-6 flex flex-wrap items-center gap-3 rounded-2xl border border-accent/30 bg-accent/10 px-5 py-4">
            <x-ui.icon name="alert" class="w-5 h-5 text-accent" />
            <div class="text-sm">
                <p class="font-medium">{{ $b['title'] }}</p>
                <p class="text-muted">{{ $b['body'] }}</p>
            </div>
            <a href="{{ $b['url'] }}" class="btn-accent btn-sm ml-auto">{{ $b['cta'] }}</a>
        </div>
    @empty
        <div class="mt-6 flex flex-wrap items-center gap-3 rounded-2xl border border-emerald-500/30 bg-emerald-500/10 px-5 py-4">
            <x-ui.icon name="check" class="w-5 h-5 text-emerald-600" />
            <div class="text-sm">
                <p class="font-medium">Payouts are on</p>
                <p class="text-muted">Stripe account {{ $profile?->stripe_account_id }}</p>
            </div>
        </div>
    @endforelse

    {{-- summary --}}
    <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="card p-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">Paid out</p>
            <p class="mt-2 text-2xl font-bold">${{ number_format($status['released'], 2) }}</p>
            @if ($status['reversed'] > 0)<p class="mt-1 text-xs text-rose-500">−${{ number_format($status['reversed'], 2) }} taken back after refunds</p>@endif
        </div>
        <div class="card p-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">Coming your way</p>
            <p class="mt-2 text-2xl font-bold">${{ number_format($status['in_escrow'], 2) }}</p>
            <p class="mt-1 text-xs text-muted">Shipped, waiting for delivery confirmation</p>
        </div>
        <div class="card p-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">To ship</p>
            <p class="mt-2 text-2xl font-bold">${{ number_format($status['pending'], 2) }}</p>
            <p class="mt-1 text-xs text-muted">Paid orders not shipped yet</p>
        </div>
        <div class="card p-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">On hold</p>
            <p class="mt-2 text-2xl font-bold {{ $status['on_hold'] > 0 ? 'text-amber-600' : '' }}">${{ number_format($status['on_hold'], 2) }}</p>
            <p class="mt-1 text-xs text-muted">Open returns &amp; disputes</p>
        </div>
    </div>

    <details class="card mt-4 p-5 text-sm">
        <summary class="cursor-pointer font-semibold">Fees on delivered orders: ${{ number_format(array_sum($fees), 2) }}</summary>
        <div class="mt-3 grid gap-2 sm:grid-cols-5">
            <div><p class="text-xs text-muted">Pan Motive commission</p><p class="font-semibold">${{ number_format($fees['platform'], 2) }}</p></div>
            <div><p class="text-xs text-muted">Offsite Ads</p><p class="font-semibold">${{ number_format($fees['offsite'], 2) }}</p></div>
            <div><p class="text-xs text-muted">Affiliate commissions</p><p class="font-semibold">${{ number_format($fees['affiliate'], 2) }}</p></div>
            <div><p class="text-xs text-muted">Shipping labels</p><p class="font-semibold">${{ number_format($fees['labels'], 2) }}</p></div>
            <div><p class="text-xs text-muted">Instant payout fees</p><p class="font-semibold">${{ number_format($fees['instant'], 2) }}</p></div>
        </div>
        <a href="{{ route('seller.finance.ledger') }}" class="mt-3 inline-block text-xs font-medium text-accent hover:underline">Full ledger &amp; P&amp;L →</a>
    </details>

    {{-- in escrow --}}
    @if ($pending->isNotEmpty())
        <h2 class="mt-8 font-bold">Coming your way</h2>
        <div class="mt-3 overflow-x-auto rounded-xl border border-line">
            <table class="w-full min-w-[640px] text-sm">
                <thead class="bg-surface-2 text-left text-xs uppercase text-muted">
                    <tr><th class="px-4 py-3">Order</th><th class="px-4 py-3">Item</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Expected</th><th class="px-4 py-3 text-right">Payout</th></tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($pending as $item)
                        <tr class="bg-surface">
                            <td class="px-4 py-3">{{ $item->order->order_number }}</td>
                            <td class="px-4 py-3">{{ $item->product_title }}</td>
                            <td class="px-4 py-3"><span class="pill">{{ $item->status->label() }}</span></td>
                            <td class="px-4 py-3 text-xs {{ str_starts_with($item->release_note, 'On hold') || str_starts_with($item->release_note, 'Waiting') ? 'text-amber-600' : 'text-muted' }}">{{ $item->release_note }}</td>
                            <td class="px-4 py-3 text-right font-semibold">${{ number_format($item->seller_payout_amount, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if ($reversed->isNotEmpty())
        <h2 class="mt-8 font-bold">Taken back after refunds</h2>
        <div class="mt-3 overflow-x-auto rounded-xl border border-line">
            <table class="w-full min-w-[560px] text-sm">
                <tbody class="divide-y divide-line">
                    @foreach ($reversed as $item)
                        <tr class="bg-surface">
                            <td class="px-4 py-3">{{ $item->updated_at->format('M j, Y') }}</td>
                            <td class="px-4 py-3">{{ $item->order->order_number }}</td>
                            <td class="px-4 py-3">{{ $item->product_title }}</td>
                            <td class="px-4 py-3 text-right font-semibold text-rose-500">−${{ number_format($item->seller_payout_amount, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- instant advances --}}
    @if ($advances->isNotEmpty())
        <h2 class="mt-8 font-bold">Instant payouts</h2>
        <div class="mt-3 overflow-x-auto rounded-xl border border-line">
            <table class="w-full min-w-[560px] text-sm">
                <thead class="bg-surface-2 text-left text-xs uppercase text-muted">
                    <tr><th class="px-4 py-3">Date</th><th class="px-4 py-3">Order</th><th class="px-4 py-3">Paid now</th><th class="px-4 py-3">Fee</th><th class="px-4 py-3">Status</th></tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($advances as $a)
                        <tr class="bg-surface">
                            <td class="px-4 py-3">{{ $a->created_at->format('M j, Y') }}</td>
                            <td class="px-4 py-3">{{ $a->orderItem->order->order_number }}</td>
                            <td class="px-4 py-3 font-semibold">${{ number_format($a->net_paid, 2) }}</td>
                            <td class="px-4 py-3 text-muted">${{ number_format($a->fee, 2) }}</td>
                            <td class="px-4 py-3"><span class="pill capitalize">{{ str_replace('_', ' ', $a->status) }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- released --}}
    <h2 class="mt-8 font-bold">Payout history</h2>
    <div class="mt-3 overflow-x-auto rounded-xl border border-line">
        <table class="w-full min-w-[560px] text-sm">
            <thead class="bg-surface-2 text-left text-xs uppercase text-muted">
                <tr><th class="px-4 py-3">Released</th><th class="px-4 py-3">Order</th><th class="px-4 py-3">Item</th><th class="px-4 py-3 text-right">Amount</th></tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($released as $item)
                    <tr class="bg-surface">
                        <td class="px-4 py-3">{{ optional($item->payout_released_at)->format('M j, Y') ?: '—' }}</td>
                        <td class="px-4 py-3">{{ $item->order->order_number }}</td>
                        <td class="px-4 py-3">{{ $item->product_title }}</td>
                        <td class="px-4 py-3 text-right font-semibold text-emerald-600 dark:text-emerald-400">+${{ number_format($item->seller_payout_amount, 2) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-12 text-center text-sm text-muted">No payouts released yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $released->links() }}</div>
</div>
@endsection
