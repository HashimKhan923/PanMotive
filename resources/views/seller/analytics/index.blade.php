@extends('layouts.seller')

@section('title', 'Analytics')

@section('content')
@php
    $max = max($series->max('total') ?: 1, 1);
    $chg = fn ($k) => \App\Services\Seller\SellerAnalyticsService::change((float) $current[$k], (float) $previous[$k]);
    $delta = function ($k) use ($chg) {
        $c = $chg($k);
        if ($c === null) return '<span class="text-muted">no prior data</span>';
        $cls = $c >= 0 ? 'text-emerald-600' : 'text-rose-500';
        return '<span class="'.$cls.'">'.($c >= 0 ? '▲' : '▼').' '.abs($c).'%</span> <span class="text-muted">vs previous period</span>';
    };
@endphp
<div>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">Analytics</h1>
            <p class="mt-1 text-sm text-muted">{{ $from->format($from->year === $to->year ? 'M j' : 'M j, Y') }} – {{ $to->format('M j, Y') }} · paid sales only (unpaid, cancelled and refunded orders left out)</p>
        </div>
        <div class="flex items-center gap-2">
            <div class="flex gap-1 rounded-lg border border-line p-1 text-sm">
                @foreach ([7 => '7d', 30 => '30d', 90 => '90d', 365 => '12m'] as $d => $label)
                    <a href="{{ route('seller.analytics.index', ['days' => $d]) }}"
                       class="rounded-md px-3 py-1 font-medium {{ $days === $d ? 'bg-accent text-accent-fg' : 'text-muted hover:text-fg' }}">{{ $label }}</a>
                @endforeach
            </div>
            <a href="{{ route('seller.analytics.export', ['days' => $days]) }}" class="btn-ghost btn-sm"><x-ui.icon name="download" class="w-4 h-4" /> CSV</a>
        </div>
    </div>

    <div class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="card p-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">Sales</p>
            <p class="mt-2 text-2xl font-bold">${{ number_format($current['revenue'], 2) }}</p>
            <p class="mt-1 text-xs">{!! $delta('revenue') !!}</p>
            <p class="text-xs text-muted">${{ number_format($current['payout'], 2) }} to you after fees</p>
        </div>
        <div class="card p-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">Orders</p>
            <p class="mt-2 text-2xl font-bold">{{ number_format($current['orders']) }}</p>
            <p class="mt-1 text-xs">{!! $delta('orders') !!}</p>
            <p class="text-xs text-muted">{{ number_format($current['units']) }} units · ${{ number_format($current['aov'], 2) }} avg order</p>
        </div>
        <div class="card p-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">Listing views</p>
            <p class="mt-2 text-2xl font-bold">{{ number_format($current['views']) }}</p>
            <p class="mt-1 text-xs">{!! $delta('views') !!}</p>
            <p class="text-xs text-muted">{{ number_format($current['visitors']) }} unique shoppers</p>
        </div>
        <div class="card p-5">
            <p class="text-xs font-semibold uppercase tracking-wide text-muted">Conversion</p>
            <p class="mt-2 text-2xl font-bold">{{ $current['conversion'] !== null ? $current['conversion'].'%' : '—' }}</p>
            <p class="mt-1 text-xs text-muted">orders ÷ unique shoppers{{ $previous['conversion'] !== null ? ' · was '.$previous['conversion'].'%' : '' }}</p>
        </div>
    </div>

    <div class="card mt-6 p-6">
        <h2 class="font-bold">Daily sales</h2>
        @if ($series->sum('total') <= 0)
            <div class="mt-6 grid h-40 place-items-center text-sm text-muted">No sales in this window.</div>
        @else
            <div class="mt-4 flex items-end gap-px" style="height:160px;">
                @foreach ($series as $p)
                    <div class="group relative flex-1">
                        <div class="w-full rounded-t bg-chart/70 group-hover:bg-accent" style="height: {{ max(2, round($p['total'] / $max * 150)) }}px"></div>
                        <div class="pointer-events-none absolute -top-12 left-1/2 z-10 hidden -translate-x-1/2 whitespace-nowrap rounded bg-brand px-2 py-1 text-[10px] text-brand-fg group-hover:block">
                            {{ \Illuminate\Support\Carbon::parse($p['date'])->format('M j') }} · ${{ number_format($p['total'], 0) }}<br>{{ $p['orders'] }} orders · {{ $p['views'] }} views
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <div class="card mt-6 p-6">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="font-bold">Listing performance</h2>
            <p class="text-xs text-muted">Listings with lots of views but few orders usually need a better price, photos or fitment info.</p>
        </div>
        @if ($listings->isEmpty())
            <p class="mt-4 text-sm text-muted">No views or sales in this window yet.</p>
        @else
            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[640px] text-sm">
                    <thead class="text-left text-xs uppercase text-muted">
                        <tr><th class="py-2">Listing</th><th class="py-2 text-right">Views</th><th class="py-2 text-right">Shoppers</th><th class="py-2 text-right">Orders</th><th class="py-2 text-right">Conv.</th><th class="py-2 text-right">Sales</th></tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($listings->take(20) as $l)
                            <tr>
                                <td class="py-2"><a href="{{ route('seller.products.edit', $l['product_id']) }}" class="font-medium hover:text-accent">{{ Str::limit($l['title'], 60) }}</a></td>
                                <td class="py-2 text-right">{{ number_format($l['views']) }}</td>
                                <td class="py-2 text-right">{{ number_format($l['visitors']) }}</td>
                                <td class="py-2 text-right">{{ $l['orders'] }}</td>
                                <td class="py-2 text-right {{ $l['visitors'] >= 20 && $l['orders'] === 0 ? 'font-semibold text-rose-500' : 'text-muted' }}">{{ $l['conversion'] !== null ? $l['conversion'].'%' : '—' }}</td>
                                <td class="py-2 text-right font-semibold">${{ number_format($l['revenue'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <div class="card p-6">
            <h2 class="font-bold">Sales by category</h2>
            @php $catMax = max($categories->max('revenue') ?: 1, 1); @endphp
            @if ($categories->isEmpty())
                <p class="mt-4 text-sm text-muted">No data.</p>
            @else
                <div class="mt-4 space-y-2">
                    @foreach ($categories as $c)
                        <div>
                            <div class="flex justify-between text-xs"><span>{{ $c->name }}</span><span class="text-muted">${{ number_format($c->revenue, 2) }}</span></div>
                            <div class="mt-1 h-2 overflow-hidden rounded-full bg-surface-2">
                                <div class="h-full rounded-full bg-chart" style="width: {{ round($c->revenue / $catMax * 100) }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
        <div class="card p-6">
            <h2 class="font-bold">Customers &amp; returns</h2>
            <div class="mt-4 grid grid-cols-2 gap-3 text-sm">
                <div><p class="text-muted">Return rate</p><p class="text-lg font-bold">{{ $return_rate }}%</p><p class="text-xs text-muted">returns ÷ deliveries this period</p></div>
                <div><p class="text-muted">Repeat buyers</p><p class="text-lg font-bold">{{ $repeat_rate }}%</p><p class="text-xs text-muted">of registered customers, all time</p></div>
            </div>
            <a href="{{ route('seller.marketing.analytics', ['days' => min($days, 90)]) }}" class="mt-4 inline-block text-xs font-medium text-accent hover:underline">Where your sales come from → Marketing insights</a>
        </div>
    </div>
</div>
@endsection
