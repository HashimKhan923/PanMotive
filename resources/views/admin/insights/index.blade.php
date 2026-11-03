@extends('layouts.admin')

@section('title', 'Insights')

@section('content')
<x-admin.header title="Insights" subtitle="What's working, what's at risk, and where to act. Everything here comes from your real orders.">
    <div class="flex rounded-xl border border-line bg-surface p-1" role="group" aria-label="Date range">
        @foreach ([7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => '1 year'] as $value => $label)
            <a href="{{ route('admin.insights.index', ['days' => $value]) }}" @class(['adm-tab !py-1 !text-xs', 'is-active' => $days === $value])>{{ $label }}</a>
        @endforeach
    </div>
</x-admin.header>

<div class="grid grid-cols-2 gap-3 lg:grid-cols-4" data-enter>
    <x-admin.stat label="Repeat buyers" :value="$loyalty['rate']" suffix="%" :decimals="1" icon="users" :hint="number_format($loyalty['repeat']).' of '.number_format($loyalty['buyers']).' buyers ordered more than once'" />
    <x-admin.stat label="Guest orders" :value="$loyalty['guest_orders']" icon="user" hint="Paid orders without an account" />
    <x-admin.stat label="Selling stores" :value="$concentration['sellers']" icon="store" hint="Stores with sales in this period" />
    <x-admin.stat label="Biggest store's share" :value="$concentration['top_share']" suffix="%" :decimals="1" icon="alert" :hint="'Top 3 stores: '.number_format($concentration['top3_share'], 1).'% of sales'" />
</div>

<div class="mt-4 grid gap-4 xl:grid-cols-3">
    <x-admin.card title="Marketplace health" subtitle="Ratings are a guide, based on common marketplace standards.">
        <x-admin.health :rows="$health" />
    </x-admin.card>

    <x-admin.card title="From visit to delivery" subtitle="Where shoppers drop off">
        @php($first = max(1, $funnel[0]['value']))
        <ol class="space-y-3.5">
            @foreach ($funnel as $i => $step)
                <li>
                    <div class="flex items-baseline justify-between gap-3 text-[13px]">
                        <span class="font-medium">{{ $step['label'] }}</span>
                        <span class="tabular-nums"><span class="font-semibold">{{ number_format($step['value']) }}</span>
                            @if ($i > 0 && $funnel[$i - 1]['value'] > 0)<span class="text-xs text-muted">{{ number_format($step['value'] / $funnel[$i - 1]['value'] * 100, 1) }}% of step before</span>@endif
                        </span>
                    </div>
                    <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-surface-2">
                        <div class="adm-bar h-full rounded-full bg-accent" style="width: {{ min(100, round($step['value'] / $first * 100, 1)) }}%; animation-delay: {{ $i * 90 }}ms"></div>
                    </div>
                </li>
            @endforeach
        </ol>
    </x-admin.card>

    <x-admin.card title="Catalog problems" subtitle="Live listings losing you sales. Click one to fix it.">
        <ul class="-my-1 space-y-1">
            @foreach ($issues as $issue)
                <li>
                    @php($openable = auth()->user()->can('catalog.manage'))
                    <{{ $openable ? 'a' : 'div' }} @if ($openable) href="{{ route('admin.products.index', ['status' => 'active', 'quality' => $issue['filter']]) }}" @endif class="group flex items-center gap-3 rounded-xl p-2 transition hover:bg-surface-2">
                        <span class="min-w-0 flex-1">
                            <span class="block text-[13px] font-semibold group-hover:text-accent">{{ $issue['label'] }}</span>
                            <span class="block text-xs text-muted">{{ $issue['hint'] }}</span>
                        </span>
                        <span class="text-lg font-bold tabular-nums {{ $issue['count'] === 0 ? 'text-muted/50' : '' }}">{{ number_format($issue['count']) }}</span>
                    </{{ $openable ? 'a' : 'div' }}>
                </li>
            @endforeach
        </ul>
    </x-admin.card>

    <x-admin.card title="Best-selling parts" subtitle="By sales in this period">
        <x-admin.bars :rows="$topProducts" prefix="$" :decimals="2" />
    </x-admin.card>
    <x-admin.card title="Where orders go" subtitle="Sales by shipping state, top 8">
        <x-admin.bars :rows="$patterns['states']" prefix="$" :decimals="2" />
    </x-admin.card>
    <x-admin.card title="Best days to sell" subtitle="Sales by day of the week, useful for timing promotions">
        <x-admin.bars :rows="$patterns['weekdays']" prefix="$" :decimals="2" />
    </x-admin.card>
</div>
@endsection
