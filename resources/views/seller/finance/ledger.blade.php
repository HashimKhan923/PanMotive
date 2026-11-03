@extends('layouts.seller')

@section('title', 'Ledger & reports')

@section('content')
@php
    $periods = ['7d' => '7 days', '30d' => '30 days', '90d' => '90 days', 'mtd' => 'Month to date', 'last_month' => 'Last month', 'ytd' => 'Year to date'];
@endphp
<div>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">Ledger &amp; reports</h1>
            <p class="mt-1 text-sm text-muted">{{ $from->format('M j, Y') }} – {{ $to->format('M j, Y') }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <div class="flex flex-wrap gap-1 rounded-lg border border-line p-1 text-sm">
                @foreach ($periods as $key => $label)
                    <a href="{{ route('seller.finance.ledger', ['period' => $key]) }}"
                       class="rounded-md px-3 py-1 font-medium {{ $period === $key ? 'bg-accent text-accent-fg' : 'text-muted hover:text-fg' }}">{{ $label }}</a>
                @endforeach
            </div>
            <a href="{{ route('seller.finance.export', ['period' => $period]) }}" class="btn-outline btn-sm">
                <x-ui.icon name="external" class="w-4 h-4" /> Export CSV
            </a>
        </div>
    </div>

    {{-- P&L --}}
    <div class="card mt-6 p-6">
        <h2 class="font-bold">Profit &amp; loss</h2>
        <div class="mt-4 divide-y divide-line text-sm">
            <div class="flex justify-between py-2"><span>Gross sales <span class="text-muted">({{ $pnl['orders'] }} orders)</span></span><span class="font-semibold">${{ number_format($pnl['gross_sales'], 2) }}</span></div>
            <div class="flex justify-between py-2 text-muted"><span>Less refunds</span><span>−${{ number_format($pnl['refunds'], 2) }}</span></div>
            <div class="flex justify-between py-2"><span>Net sales</span><span class="font-semibold">${{ number_format($pnl['net_sales'], 2) }}</span></div>
            <div class="flex justify-between py-2 text-muted"><span>Less platform fees</span><span>−${{ number_format($pnl['platform_fees'], 2) }}</span></div>
            <div class="flex justify-between py-2 text-muted"><span>Less advertising</span><span>−${{ number_format($pnl['ad_spend'], 2) }}</span></div>
            @if (($pnl['offsite_ad_fees'] ?? 0) > 0)
                <div class="flex justify-between py-2 text-muted"><span>Less Offsite Ads fees</span><span>−${{ number_format($pnl['offsite_ad_fees'], 2) }}</span></div>
            @endif
            @if (($pnl['label_fees'] ?? 0) > 0)
                <div class="flex justify-between py-2 text-muted"><span>Less shipping labels</span><span>−${{ number_format($pnl['label_fees'], 2) }}</span></div>
            @endif
            @if (($pnl['affiliate_fees'] ?? 0) > 0)
                <div class="flex justify-between py-2 text-muted"><span>Less affiliate commissions</span><span>−${{ number_format($pnl['affiliate_fees'], 2) }}</span></div>
            @endif
            <div class="flex justify-between py-2 text-muted"><span>Less instant-payout fees</span><span>−${{ number_format($pnl['instant_payout_fees'], 2) }}</span></div>
            <div class="flex justify-between py-3 text-base"><span class="font-bold">Net earnings</span><span class="font-bold {{ $pnl['net_earnings'] < 0 ? 'text-rose-500' : 'text-emerald-600' }}">${{ number_format($pnl['net_earnings'], 2) }}</span></div>
        </div>
    </div>

    {{-- payout reconciliation --}}
    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="card p-5"><p class="text-xs font-semibold uppercase tracking-wide text-muted">Released</p><p class="mt-2 text-xl font-bold">${{ number_format($pnl['payout_status']['released'], 2) }}</p></div>
        <div class="card p-5"><p class="text-xs font-semibold uppercase tracking-wide text-muted">In escrow</p><p class="mt-2 text-xl font-bold">${{ number_format($pnl['payout_status']['in_escrow'], 2) }}</p></div>
        <div class="card p-5"><p class="text-xs font-semibold uppercase tracking-wide text-muted">Awaiting fulfilment</p><p class="mt-2 text-xl font-bold">${{ number_format($pnl['payout_status']['pending'], 2) }}</p></div>
        <div class="card p-5"><p class="text-xs font-semibold uppercase tracking-wide text-muted">On hold</p><p class="mt-2 text-xl font-bold">${{ number_format($pnl['payout_status']['on_hold'], 2) }}</p><p class="text-xs text-muted">open returns &amp; disputes</p></div>
    </div>

    {{-- ledger --}}
    <div class="card mt-6 p-6">
        <h2 class="font-bold">Transactions</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-xs uppercase tracking-wide text-muted">
                    <tr class="text-left">
                        <th class="py-2">Date</th><th class="py-2">Type</th><th class="py-2">Ref</th>
                        <th class="py-2">Description</th>
                        <th class="py-2 text-right">Gross</th><th class="py-2 text-right">Fee</th><th class="py-2 text-right">Net</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($entries as $e)
                        <tr>
                            <td class="whitespace-nowrap py-2 text-muted">{{ $e['date']->format('M j') }}</td>
                            <td class="py-2"><span class="pill capitalize">{{ str_replace('_', ' ', $e['type']) }}</span></td>
                            <td class="py-2 font-mono text-xs">{{ $e['ref'] }}</td>
                            <td class="py-2">{{ $e['description'] }}</td>
                            <td class="py-2 text-right">{{ $e['gross'] != 0 ? '$'.number_format($e['gross'], 2) : '—' }}</td>
                            <td class="py-2 text-right text-muted">{{ $e['fee'] != 0 ? '$'.number_format($e['fee'], 2) : '—' }}</td>
                            <td class="py-2 text-right font-semibold {{ $e['net'] < 0 ? 'text-rose-500' : '' }}">${{ number_format($e['net'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-10 text-center text-muted">No transactions in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- released payout references --}}
    @if ($payouts->isNotEmpty())
        <div class="card mt-6 p-6">
            <h2 class="font-bold">Released payouts</h2>
            <p class="mt-1 text-xs text-muted">Match these against your connected Stripe account's transfer history.</p>
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-line">
                        @foreach ($payouts as $p)
                            <tr>
                                <td class="py-2 font-mono text-xs">{{ $p['order'] }}</td>
                                <td class="py-2 text-muted">{{ optional($p['released_at'])->format('M j, Y') }}</td>
                                <td class="py-2 font-mono text-xs text-muted">{{ $p['reference'] ?? '—' }}</td>
                                <td class="py-2 text-right font-semibold">${{ number_format($p['amount'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
@endsection
