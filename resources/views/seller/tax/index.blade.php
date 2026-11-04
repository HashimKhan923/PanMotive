@extends('layouts.seller')

@section('title', 'Tax & statements')

@section('content')
@php
    $pct = $threshold > 0 ? min(100, round($gross / $threshold * 100)) : 0;
    $txnPct = $minTxns > 0 ? min(100, round($txns / $minTxns * 100)) : 0;
    $willIssue = $gross > $threshold && $txns > $minTxns;
    $expenses = $pnl['platform_fees'] + $pnl['ad_spend'] + $pnl['offsite_ad_fees'] + ($pnl['affiliate_fees'] ?? 0) + ($pnl['label_fees'] ?? 0) + $pnl['instant_payout_fees'];
@endphp
<div class="max-w-3xl">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">Tax &amp; statements</h1>
            <p class="mt-1 text-sm text-muted">Your yearly numbers for income tax, and monthly statements for your bookkeeper.</p>
        </div>
        <form method="GET">
            <select name="year" onchange="this.form.submit()" class="input h-10 w-auto text-sm" aria-label="Tax year">
                @foreach ($years as $y)<option value="{{ $y }}" @selected($y === $year)>{{ $y }}</option>@endforeach
            </select>
        </form>
    </div>

    {{-- 1099-K progress --}}
    <div class="card mt-6 p-6" data-reveal>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-bold">{{ $year }} Form 1099-K</h2>
            <span class="text-xs text-muted">issued when you pass <strong>both</strong> ${{ number_format($threshold, 0) }} and {{ $minTxns }} transactions</span>
        </div>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
                <div class="flex justify-between text-xs"><span>Gross payments</span><span class="font-semibold">${{ number_format($gross, 2) }} / ${{ number_format($threshold, 0) }}</span></div>
                <div class="mt-1 h-2.5 overflow-hidden rounded-full bg-surface-2"><div class="h-full rounded-full {{ $gross > $threshold ? 'bg-accent' : 'bg-emerald-500' }}" style="width: {{ $pct }}%"></div></div>
            </div>
            <div>
                <div class="flex justify-between text-xs"><span>Transactions</span><span class="font-semibold">{{ $txns }} / {{ $minTxns }}</span></div>
                <div class="mt-1 h-2.5 overflow-hidden rounded-full bg-surface-2"><div class="h-full rounded-full {{ $txns > $minTxns ? 'bg-accent' : 'bg-emerald-500' }}" style="width: {{ $txnPct }}%"></div></div>
            </div>
        </div>
        <p class="mt-3 text-sm">
            @if ($willIssue)
                <span class="rounded bg-surface-2 px-1.5 text-xs font-semibold text-fg">A 1099-K will be issued for {{ $year }}</span> — it reports gross payments before fees and refunds.
            @else
                <span class="text-muted">Below the federal reporting threshold so far. Some states require a 1099-K at lower amounts.</span>
            @endif
        </p>
        <p class="mt-2 text-xs text-muted">Either way, all of your sales income is taxable — the 1099-K only decides whether a form is sent to you and the IRS. This page isn't tax advice.</p>
    </div>

    {{-- Year totals --}}
    <div class="mt-6 grid gap-4 sm:grid-cols-3" data-reveal-group>
        <div class="card p-5"><p class="text-xs font-semibold uppercase tracking-wide text-muted">Gross sales</p><p class="mt-2 text-2xl font-bold">${{ number_format($pnl['gross_sales'], 2) }}</p><p class="text-xs text-muted">{{ $pnl['orders'] }} sales</p></div>
        <div class="card p-5"><p class="text-xs font-semibold uppercase tracking-wide text-muted">Refunds</p><p class="mt-2 text-2xl font-bold">{{ $pnl['refunds'] > 0 ? '−' : '' }}${{ number_format($pnl['refunds'], 2) }}</p></div>
        <div class="card p-5"><p class="text-xs font-semibold uppercase tracking-wide text-muted">Selling expenses</p><p class="mt-2 text-2xl font-bold">{{ $expenses > 0 ? '−' : '' }}${{ number_format($expenses, 2) }}</p><p class="text-xs text-muted">usually deductible</p></div>
    </div>

    <div class="card mt-4 p-6 text-sm" data-reveal>
        <h2 class="font-bold">Expenses on Panmotive in {{ $year }}</h2>
        <div class="mt-3 divide-y divide-line">
            <div class="flex justify-between py-2"><span>Commission</span><span>${{ number_format($pnl['platform_fees'], 2) }}</span></div>
            <div class="flex justify-between py-2"><span>Advertising (ad credit spent)</span><span>${{ number_format($pnl['ad_spend'], 2) }}</span></div>
            <div class="flex justify-between py-2"><span>Offsite Ads fees</span><span>${{ number_format($pnl['offsite_ad_fees'], 2) }}</span></div>
            <div class="flex justify-between py-2"><span>Affiliate commissions</span><span>${{ number_format($pnl['affiliate_fees'] ?? 0, 2) }}</span></div>
            <div class="flex justify-between py-2"><span>Shipping labels bought on Panmotive</span><span>${{ number_format($pnl['label_fees'] ?? 0, 2) }}</span></div>
            <div class="flex justify-between py-2"><span>Instant payout fees</span><span>${{ number_format($pnl['instant_payout_fees'], 2) }}</span></div>
            <div class="flex justify-between py-2 font-bold"><span>Net earnings</span><span>${{ number_format($pnl['net_earnings'], 2) }}</span></div>
        </div>
        <p class="mt-3 text-xs text-muted">Labels, parts and packaging you buy elsewhere aren't included — add those from your own records.</p>
    </div>

    {{-- Sales tax --}}
    <div class="mt-4 rounded-2xl border border-line bg-surface-2 p-5 text-sm" data-reveal>
        <p class="font-semibold">Sales tax</p>
        <p class="mt-1 text-muted">{{ config('marketplace.sales_tax.collecting')
            ? 'Panmotive collects and remits sales tax on your orders as the marketplace facilitator — you don\'t need to.'
            : 'Panmotive does not yet calculate sales tax at checkout. Until it does, sales-tax collection isn\'t handled for you — talk to a tax professional about your obligations.' }}</p>
    </div>

    {{-- Monthly statements --}}
    <div class="card mt-6 p-6" data-reveal>
        <div class="flex items-center justify-between">
            <h2 class="font-bold">Monthly statements</h2>
            <a href="{{ route('seller.tax.statement', ['year' => $year]) }}" class="btn-outline btn-sm">Download full year</a>
        </div>
        <table class="mt-4 w-full text-sm">
            <tbody class="divide-y divide-line">
                @foreach ($months as $m)
                    <tr>
                        <td class="py-2">{{ $m['month'] }}</td>
                        <td class="py-2 text-right font-medium">${{ number_format($m['gross'], 2) }}</td>
                        <td class="py-2 text-right">
                            @if ($m['gross'] > 0)
                                <a href="{{ route('seller.tax.statement', ['year' => $year, 'month' => $m['num']]) }}" class="text-xs font-medium text-accent hover:underline">CSV</a>
                            @else
                                <span class="text-xs text-muted">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
