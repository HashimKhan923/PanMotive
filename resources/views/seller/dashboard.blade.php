@extends('layouts.seller')

@section('title', 'Dashboard')

@section('content')
@php
    $totals = $series->pluck('total');
    $max = max($totals->max() ?: 1, 1);
    $needsAttention = collect([
        ($stats['late_shipments'] ?? 0) > 0 ? ['route' => route('seller.orders.index', ['status' => 'late']), 'label' => $stats['late_shipments'] . ' ' . Str::plural('order', $stats['late_shipments']) . ' late to ship', 'icon' => 'alert'] : null,
        $stats['pending_orders'] > 0 ? ['route' => route('seller.orders.index', ['status' => 'new']), 'label' => $stats['pending_orders'] . ' new ' . Str::plural('order', $stats['pending_orders']) . ' to accept', 'icon' => 'bell'] : null,
        ($stats['open_offers'] ?? 0) > 0 ? ['route' => route('seller.offers.index'), 'label' => $stats['open_offers'] . ' ' . Str::plural('offer', $stats['open_offers']) . ' awaiting a response', 'icon' => 'tag'] : null,
        $stats['to_ship'] > 0 ? ['route' => route('seller.orders.index', ['status' => 'seller_accepted']), 'label' => $stats['to_ship'] . ' ' . Str::plural('order', $stats['to_ship']) . ' ready to ship', 'icon' => 'truck'] : null,
        $stats['open_returns'] > 0 ? ['route' => route('seller.returns.index'), 'label' => $stats['open_returns'] . ' open return ' . Str::plural('request', $stats['open_returns']), 'icon' => 'refresh'] : null,
        ($stats['unanswered_questions'] ?? 0) > 0 ? ['route' => route('seller.questions.index'), 'label' => $stats['unanswered_questions'] . ' shopper ' . Str::plural('question', $stats['unanswered_questions']) . ' to answer', 'icon' => 'help'] : null,
        ($stats['support_replies'] ?? 0) > 0 ? ['route' => route('seller.support.index'), 'label' => 'Panmotive support replied to ' . $stats['support_replies'] . ' ' . Str::plural('request', $stats['support_replies']), 'icon' => 'message'] : null,
        $stats['out_of_stock'] > 0 ? ['route' => route('seller.products.index', ['stock' => 'out']), 'label' => $stats['out_of_stock'] . ' active ' . Str::plural('listing', $stats['out_of_stock']) . ' out of stock', 'icon' => 'alert'] : null,
        $stats['low_stock'] > 0 ? ['route' => route('seller.products.index', ['stock' => 'low']), 'label' => $stats['low_stock'] . ' ' . Str::plural('listing', $stats['low_stock']) . ' low on stock', 'icon' => 'alert'] : null,
    ])->filter()->values();
@endphp

<div class="space-y-8">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">Welcome back{{ auth()->user()->name ? ', ' . Str::before(auth()->user()->name, ' ') : '' }}</h1>
            <p class="mt-1 text-sm text-muted">Here's how {{ auth()->user()->sellerProfile?->business_name ?? 'your store' }} is doing.</p>
        </div>
    </div>

    @unless ($payoutReady)
        <div class="flex flex-wrap items-center gap-3 rounded-2xl border border-accent/30 bg-accent/10 px-5 py-4">
            <x-ui.icon name="alert" class="w-6 h-6 text-fg" />
            <p class="text-sm font-medium">Finish connecting your payout account to receive funds when orders are delivered.</p>
            <a href="{{ route('seller.onboarding.start') }}" class="btn-accent btn-sm ml-auto">Finish setup</a>
        </div>
    @endunless

    {{-- Store health --}}
    @php($lightHex = ['green' => 'bg-emerald-500', 'amber' => 'bg-amber-500', 'red' => 'bg-rose-500'])
    <a href="{{ route('seller.scorecard.index') }}" class="card lift flex flex-wrap items-center gap-x-6 gap-y-2 p-4" data-reveal>
        <span class="flex items-center gap-2 font-semibold">
            <span class="h-2.5 w-2.5 rounded-full {{ $lightHex[$health['overall']] }} {{ $health['overall'] !== 'green' ? 'animate-pulse' : '' }}"></span>
            Store health: {{ ['green' => 'Good standing', 'amber' => 'Watch these', 'red' => 'Needs attention'][$health['overall']] }}
        </span>
        @foreach ($health['signals'] as $s)
            <span class="flex items-center gap-1.5 text-xs text-muted">
                <span class="h-2 w-2 rounded-full {{ $lightHex[$s['level']] }}"></span>{{ $s['label'] }} {{ $s['value'] }}
            </span>
        @endforeach
        <x-ui.icon name="chevron-right" class="ml-auto w-4 h-4 text-muted" />
    </a>

    {{-- Stat cards --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="card p-5">
            <div class="flex items-center justify-between text-muted">
                <span class="text-xs font-semibold uppercase tracking-wide">This month</span>
                <x-ui.icon name="chart" class="w-4 h-4" />
            </div>
            <p class="mt-2 text-2xl font-bold">${{ number_format($stats['month_sales'], 2) }}</p>
            <p class="mt-1 text-xs text-muted">${{ number_format($stats['lifetime_sales'], 2) }} lifetime</p>
        </div>
        <div class="card p-5">
            <div class="flex items-center justify-between text-muted">
                <span class="text-xs font-semibold uppercase tracking-wide">In escrow</span>
                <x-ui.icon name="clock" class="w-4 h-4" />
            </div>
            <p class="mt-2 text-2xl font-bold">${{ number_format($stats['pending_payout'], 2) }}</p>
            <p class="mt-1 text-xs text-muted">Released on delivery</p>
        </div>
        <div class="card p-5">
            <div class="flex items-center justify-between text-muted">
                <span class="text-xs font-semibold uppercase tracking-wide">Paid out</span>
                <x-ui.icon name="dollar" class="w-4 h-4" />
            </div>
            <p class="mt-2 text-2xl font-bold">${{ number_format($stats['released_payout'], 2) }}</p>
            <a href="{{ route('seller.payouts.index') }}" class="mt-1 inline-block text-xs font-medium text-fg underline underline-offset-2">View payouts</a>
        </div>
        <div class="card p-5">
            <div class="flex items-center justify-between text-muted">
                <span class="text-xs font-semibold uppercase tracking-wide">Active listings</span>
                <x-ui.icon name="box" class="w-4 h-4" />
            </div>
            <p class="mt-2 text-2xl font-bold">{{ number_format($stats['active_listings']) }}</p>
            <p class="mt-1 text-xs text-muted">
                @if ($stats['rating']) <x-ui.icon name="star" class="mr-0.5 inline w-4 h-4 fill-current text-fg" /> {{ number_format($stats['rating'], 1) }} ({{ $stats['rating_count'] }}) @else No ratings yet @endif
            </p>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Sales chart --}}
        <div class="card p-6 lg:col-span-2">
            <div class="flex items-center justify-between">
                <h2 class="font-bold">Sales — last 30 days</h2>
                <span class="text-sm text-muted">${{ number_format($totals->sum(), 2) }}</span>
            </div>
            @if ($totals->sum() <= 0)
                <div class="mt-6 grid h-40 place-items-center text-sm text-muted">No sales in this window yet.</div>
            @else
                <div class="mt-4 flex items-end gap-1" style="height: 160px;">
                    @foreach ($series as $p)
                        <div class="group relative flex-1">
                            <div class="w-full rounded-t bg-chart/70 transition group-hover:bg-accent"
                                 style="height: {{ max(2, round($p['total'] / $max * 150)) }}px"></div>
                            <div class="pointer-events-none absolute -top-9 left-1/2 hidden -translate-x-1/2 whitespace-nowrap rounded bg-brand px-2 py-1 text-[10px] text-brand-fg group-hover:block">
                                {{ \Illuminate\Support\Carbon::parse($p['date'])->format('M j') }} · ${{ number_format($p['total'], 0) }}
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Needs attention --}}
        <div class="card p-6">
            <h2 class="font-bold">Needs attention</h2>
            @if ($needsAttention->isEmpty())
                <div class="mt-6 grid place-items-center py-6 text-center text-sm text-muted">
                    <x-ui.icon name="check" class="w-8 h-8 text-emerald-500" />
                    <p class="mt-2">You're all caught up.</p>
                </div>
            @else
                <ul class="mt-4 space-y-2">
                    @foreach ($needsAttention as $item)
                        <li>
                            <a href="{{ $item['route'] }}" class="flex items-center gap-3 rounded-lg border border-line px-3 py-2.5 text-sm hover:border-accent hover:text-accent">
                                <x-ui.icon :name="$item['icon']" class="w-4 h-4 shrink-0" />
                                <span>{{ $item['label'] }}</span>
                                <x-ui.icon name="chevron-right" class="ml-auto w-4 h-4" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- Recent orders --}}
        <div class="card p-6">
            <div class="flex items-center justify-between">
                <h2 class="font-bold">Recent orders</h2>
                <a href="{{ route('seller.orders.index') }}" class="text-sm font-medium text-fg underline underline-offset-2">All orders</a>
            </div>
            @if ($recentOrders->isEmpty())
                <p class="mt-6 text-sm text-muted">No orders yet.</p>
            @else
                <ul class="mt-4 divide-y divide-line">
                    @foreach ($recentOrders as $item)
                        <li class="flex items-center justify-between gap-3 py-3">
                            <div class="min-w-0">
                                <a href="{{ route('seller.orders.show', $item) }}" class="font-medium hover:text-accent">{{ $item->product_title }}</a>
                                <p class="text-xs text-muted">{{ $item->order->order_number }} · {{ $item->created_at->diffForHumans() }}</p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-semibold">${{ number_format($item->line_total, 2) }}</p>
                                <span class="pill">{{ $item->status->label() }}</span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- Top / low stock --}}
        <div class="card p-6">
            <h2 class="font-bold">Top sellers</h2>
            @if ($topProducts->isEmpty())
                <p class="mt-6 text-sm text-muted">No sales data yet.</p>
            @else
                <ul class="mt-4 space-y-2 text-sm">
                    @foreach ($topProducts as $product)
                        <li class="flex items-center justify-between gap-3">
                            <a href="{{ route('seller.products.edit', $product) }}" class="truncate font-medium hover:text-accent">{{ $product->title }}</a>
                            <span class="shrink-0 text-muted">{{ $product->order_items_count }} sold</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($lowStockProducts->isNotEmpty())
                <h3 class="mt-6 text-xs font-semibold uppercase tracking-wide text-muted">Restock soon</h3>
                <ul class="mt-2 space-y-2 text-sm">
                    @foreach ($lowStockProducts as $product)
                        <li class="flex items-center justify-between gap-3">
                            <a href="{{ route('seller.products.edit', $product) }}" class="truncate hover:text-accent">{{ $product->title }}</a>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold {{ $product->quantity <= 0 ? 'bg-rose-500/10 text-rose-500' : 'bg-amber-500/10 text-amber-600' }}">
                                {{ $product->quantity }} left
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
@endsection
