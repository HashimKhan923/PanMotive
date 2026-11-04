@extends('layouts.seller')

@section('title', 'Marketing insights')

@section('content')
@php
    $srcMax = max(collect($sources)->max('revenue') ?: 1, 1);
    $offsets = range(0, 5);
@endphp
<div>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">Marketing insights</h1>
            <p class="mt-1 text-sm text-muted">Where your sales come from · last {{ $days }} days</p>
        </div>
        <div class="flex gap-1 rounded-lg border border-line p-1 text-sm">
            @foreach ([7 => '7d', 30 => '30d', 90 => '90d'] as $d => $label)
                <a href="{{ route('seller.marketing.analytics', ['days' => $d]) }}"
                   class="rounded-md px-3 py-1 font-medium {{ $days === $d ? 'bg-accent text-accent-fg' : 'text-muted hover:text-fg' }}">{{ $label }}</a>
            @endforeach
        </div>
    </div>

    {{-- funnel headline --}}
    <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="card p-5"><p class="text-xs font-semibold uppercase tracking-wide text-muted">Sales</p><p class="mt-2 text-2xl font-bold">${{ number_format($funnel['revenue'], 2) }}</p><p class="mt-1 text-xs text-muted">{{ number_format($funnel['orders']) }} {{ Str::plural('order', $funnel['orders']) }}</p></div>
        <div class="card p-5"><p class="text-xs font-semibold uppercase tracking-wide text-muted">From marketing</p><p class="mt-2 text-2xl font-bold">{{ $funnel['attributed_share'] }}%</p><p class="mt-1 text-xs text-muted">of orders came via a tracked channel</p></div>
        <div class="card p-5"><p class="text-xs font-semibold uppercase tracking-wide text-muted">Marketing clicks</p><p class="mt-2 text-2xl font-bold">{{ number_format($funnel['visits']) }}</p><p class="mt-1 text-xs text-muted">{{ $funnel['conv'] }}% click → order</p></div>
        <div class="card p-5"><p class="text-xs font-semibold uppercase tracking-wide text-muted">Avg order value</p><p class="mt-2 text-2xl font-bold">${{ number_format($funnel['aov'], 2) }}</p></div>
    </div>

    {{-- traffic by source --}}
    <div class="card mt-6 p-6">
        <h2 class="font-bold">Revenue by channel</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="w-full min-w-[640px] text-sm">
                <thead class="text-left text-xs uppercase text-muted">
                    <tr>
                        <th class="py-2">Channel</th>
                        <th class="py-2 text-right">Clicks</th>
                        <th class="py-2 text-right">Orders</th>
                        <th class="py-2 text-right">Conv.</th>
                        <th class="py-2 text-right">Revenue</th>
                        <th class="py-2 text-right">Cost</th>
                        <th class="py-2 text-right" title="Revenue for every $1 spent">Return</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($sources as $s)
                        <tr>
                            <td class="py-2.5">
                                <span class="font-medium">{{ $s['label'] }}</span>
                                <div class="mt-1 h-1.5 w-40 overflow-hidden rounded-full bg-surface-2">
                                    <div class="h-full rounded-full bg-chart" style="width: {{ round($s['revenue'] / $srcMax * 100) }}%"></div>
                                </div>
                            </td>
                            <td class="py-2.5 text-right">{{ $s['source'] === 'organic' ? '—' : number_format($s['clicks']) }}</td>
                            <td class="py-2.5 text-right">{{ number_format($s['orders']) }}</td>
                            <td class="py-2.5 text-right text-muted">{{ $s['clicks'] > 0 ? $s['conv'].'%' : '—' }}</td>
                            <td class="py-2.5 text-right font-semibold">${{ number_format($s['revenue'], 2) }}</td>
                            <td class="py-2.5 text-right text-muted">{{ $s['cost'] > 0 ? '$'.number_format($s['cost'], 2) : '—' }}</td>
                            <td class="py-2.5 text-right font-medium {{ $s['roas'] !== null && $s['roas'] < 1 ? 'text-rose-500' : '' }}">{{ $s['roas'] !== null ? $s['roas'].'×' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-4 text-xs text-muted">Last-touch attribution within each channel's window. An order reached through two channels counts in both rows but only once in totals. Cost = ad credit spent, affiliate commissions and Offsite Ads fees in this period.</p>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        {{-- customer LTV --}}
        <div class="card p-6">
            <h2 class="font-bold">Customer value</h2>
            <div class="mt-4 grid grid-cols-3 gap-3 text-sm">
                <div><p class="text-muted">Customers</p><p class="text-lg font-bold">{{ number_format($ltv['customers']) }}</p></div>
                <div><p class="text-muted">Avg lifetime</p><p class="text-lg font-bold">${{ number_format($ltv['avg_ltv'], 2) }}</p></div>
                <div><p class="text-muted">Repeat rate</p><p class="text-lg font-bold">{{ $ltv['repeat_rate'] }}%</p></div>
            </div>
            @if (! empty($ltv['top']))
                <table class="mt-4 w-full text-sm">
                    <tbody class="divide-y divide-line">
                        @foreach ($ltv['top'] as $row)
                            <tr>
                                <td class="py-2">{{ $row['name'] }}<span class="block text-xs text-muted">{{ $row['orders'] }} {{ Str::plural('order', $row['orders']) }}</span></td>
                                <td class="py-2 text-right font-semibold">${{ number_format($row['spent'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="mt-4 text-sm text-muted">No customers yet.</p>
            @endif
        </div>

        {{-- cohort retention --}}
        <div class="card p-6">
            <h2 class="font-bold">Cohort retention</h2>
            <p class="mt-1 text-xs text-muted">Share of each month's new buyers who ordered again in later months.</p>
            @if (! empty($cohorts))
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-center text-xs">
                        <thead class="text-muted">
                            <tr>
                                <th class="py-1 text-left font-medium">Cohort</th>
                                <th class="py-1 font-medium">Size</th>
                                @foreach ($offsets as $o)<th class="py-1 font-medium">M{{ $o }}</th>@endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($cohorts as $c)
                                <tr class="border-t border-line">
                                    <td class="py-1.5 text-left font-medium">{{ \Illuminate\Support\Carbon::parse($c['cohort'].'-01')->format('M Y') }}</td>
                                    <td class="py-1.5">{{ $c['size'] }}</td>
                                    @foreach ($offsets as $o)
                                        @php($v = $c['retention'][$o] ?? 0)
                                        <td class="py-1.5">
                                            <span class="inline-block w-full rounded px-1 py-0.5"
                                                  style="background: rgb(var(--c-chart) / {{ $v > 0 ? min(1, $v / 100 + 0.08) : 0 }}); color: {{ $v > 55 ? 'rgb(var(--c-bg))' : 'inherit' }}">
                                                {{ $o === 0 ? '100%' : ($v > 0 ? $v.'%' : '·') }}
                                            </span>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="mt-4 text-sm text-muted">Not enough order history yet.</p>
            @endif
        </div>
    </div>

    {{-- campaign attribution --}}
    <div class="card mt-6 p-6">
        <h2 class="font-bold">Campaign performance</h2>
        @if (! empty($campaigns))
            <div class="mt-4 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs uppercase tracking-wide text-muted">
                        <tr class="text-left">
                            <th class="py-2">Campaign</th>
                            <th class="py-2">Channel</th>
                            <th class="py-2 text-right">Sent</th>
                            <th class="py-2 text-right">Opened</th>
                            <th class="py-2 text-right">Clicked</th>
                            <th class="py-2 text-right">Orders</th>
                            <th class="py-2 text-right">Revenue</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($campaigns as $c)
                            <tr>
                                <td class="py-2 font-medium">{{ $c['name'] }}</td>
                                <td class="py-2 capitalize text-muted">{{ $c['channel'] }}</td>
                                <td class="py-2 text-right">{{ number_format($c['recipients']) }}</td>
                                <td class="py-2 text-right">{{ number_format($c['opened']) }}</td>
                                <td class="py-2 text-right">{{ number_format($c['clicked']) }}</td>
                                <td class="py-2 text-right">{{ number_format($c['orders']) }}</td>
                                <td class="py-2 text-right font-semibold">${{ number_format($c['revenue'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <p class="mt-4 text-sm text-muted">No campaigns sent yet. <a href="{{ route('seller.campaigns.index') }}" class="text-accent hover:underline">Create one →</a></p>
        @endif
    </div>
</div>
@endsection
