@extends('layouts.admin')

@section('title', 'Finance overview')

@section('content')
<x-admin.header title="Finance overview" subtitle="What the platform earned, and what it holds for other people.">
    <a href="{{ route('admin.finance.ledger') }}" class="btn-outline btn-sm">Commission ledger</a>
</x-admin.header>

@unless ($taxCollecting)
    <div class="mb-4 flex items-start gap-2 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-700 dark:text-amber-300">
        <x-ui.icon name="alert" class="mt-0.5 w-4 h-4 shrink-0" />
        <span>Sales tax is not being collected yet. US marketplaces must normally collect it, so fix this before launch.</span>
    </div>
@endunless

<x-admin.filters>
    <label class="text-xs text-muted" for="f-from">From</label>
    <input id="f-from" type="date" name="from" value="{{ $from->toDateString() }}" class="input h-9 !w-auto !py-0 text-[13px]">
    <label class="text-xs text-muted" for="f-to">To</label>
    <input id="f-to" type="date" name="to" value="{{ $to->toDateString() }}" class="input h-9 !w-auto !py-0 text-[13px]">
</x-admin.filters>

<div class="grid grid-cols-2 gap-3 lg:grid-cols-4" data-enter>
    <x-admin.stat label="Gross sales" :value="$sales" prefix="$" :decimals="2" icon="dollar" hint="Paid orders" />
    <x-admin.stat label="Platform income" :value="$totalRevenue" prefix="$" :decimals="2" icon="chart" hint="All income sources below" />
    <x-admin.stat label="Refunded" :value="$refunded" prefix="$" :decimals="2" icon="refresh" hint="Line value refunded" />
    <x-admin.stat label="Paid to sellers" :value="$paidOut" prefix="$" :decimals="2" icon="archive" hint="Payouts released" />
</div>

<div class="mt-4 grid gap-4 xl:grid-cols-3">
    <div class="xl:col-span-2">
        <x-admin.card title="Commission by month" subtitle="Last 12 months, refunded and cancelled lines excluded">
            <x-admin.line-chart :labels="$monthly['labels']" :values="$monthly['commission']" prefix="$" :decimals="2" name="Commission" />
        </x-admin.card>
    </div>
    <div class="space-y-4">
        <x-admin.card title="Income by source" subtitle="In the selected dates">
            <x-admin.bars :rows="collect($revenue)->filter()->map(fn ($value, $label) => ['label' => $label, 'value' => $value])->sortByDesc('value')->values()" prefix="$" :decimals="2" empty="No income in these dates." />
        </x-admin.card>
        <x-admin.card title="Money we hold for others" subtitle="Right now, whatever the dates">
            <dl class="space-y-2.5 text-[13px] tabular-nums">
                <div class="flex justify-between gap-3"><dt class="text-muted">Owed to sellers</dt><dd class="font-semibold">${{ number_format($owedSellers, 2) }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted">Buyer wallets</dt><dd class="font-semibold">${{ number_format($walletOwed, 2) }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted">Seller ad credit</dt><dd class="font-semibold">${{ number_format($adCreditOwed, 2) }}</dd></div>
                <div class="flex justify-between gap-3 border-t border-line/60 pt-2.5"><dt class="font-medium">Total</dt><dd class="font-bold">${{ number_format($owedSellers + $walletOwed + $adCreditOwed, 2) }}</dd></div>
            </dl>
        </x-admin.card>
    </div>
</div>
@endsection
