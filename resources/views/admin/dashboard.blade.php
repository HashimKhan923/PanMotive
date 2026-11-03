@extends('layouts.admin')

@section('title', 'Overview')

@section('content')
<x-admin.header title="Good {{ now()->hour < 12 ? 'morning' : (now()->hour < 18 ? 'afternoon' : 'evening') }}, {{ \Illuminate\Support\Str::before(auth()->user()->name, ' ') }}"
                subtitle="Here is how Pan Motive is doing, and what needs you today.">
    <div class="flex rounded-xl border border-line bg-surface p-1" role="group" aria-label="Date range">
        @foreach ([7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => '1 year'] as $value => $label)
            <a href="{{ route('admin.dashboard', ['days' => $value]) }}" @class(['adm-tab !py-1 !text-xs', 'is-active' => $days === $value])>{{ $label }}</a>
        @endforeach
    </div>
</x-admin.header>

<div class="grid grid-cols-2 gap-3 lg:grid-cols-4" data-enter>
    @if ($canFinance)
        <x-admin.stat label="Gross sales" :value="$stats['sales']" prefix="$" :decimals="2" :delta="$stats['sales_delta']" icon="dollar" hint="Paid orders, before refunds" />
        <x-admin.stat label="Commission earned" :value="$stats['commission']" prefix="$" :decimals="2" :delta="$stats['commission_delta']" icon="chart" hint="Excludes refunded and cancelled lines" />
    @endif
    <x-admin.stat label="Paid orders" :value="$stats['orders']" :delta="$stats['orders_delta']" icon="list" hint="In this period" />
    @if ($canFinance)
        <x-admin.stat label="Average order" :value="$stats['aov']" prefix="$" :decimals="2" icon="tag" hint="Sales ÷ paid orders" />
        <x-admin.stat label="Refunded" :value="$stats['refunds']" prefix="$" :decimals="2" icon="refresh" hint="Lines refunded in this period" />
    @endif
    <x-admin.stat label="New buyers" :value="$stats['new_buyers']" :delta="$stats['new_buyers_delta']" icon="users" hint="Accounts created" />
    <x-admin.stat label="Active sellers" :value="$stats['active_sellers']" icon="store" hint="Approved stores" />
    <x-admin.stat label="Live listings" :value="$stats['live_listings']" icon="box" hint="Shoppers can buy these now" />
</div>

<div class="mt-4 grid gap-4 xl:grid-cols-3">
    <div class="space-y-4 xl:col-span-2">
        @if ($canFinance)
            <x-admin.card title="Sales over time" subtitle="Paid orders per day">
                <x-admin.line-chart :labels="$trend['labels']" :values="$trend['sales']" :previous="$previousTrend['sales']" prefix="$" :decimals="2" name="This period" previous-name="Previous period" />
            </x-admin.card>
        @endif

        <div class="grid gap-4 md:grid-cols-2">
            @if ($canFinance)
                <x-admin.card title="Top categories" subtitle="By sales in this period">
                    <x-admin.bars :rows="$topCategories" prefix="$" :decimals="2" />
                </x-admin.card>
                <x-admin.card title="Top sellers" subtitle="By sales in this period">
                    <x-admin.bars :rows="$topSellers" prefix="$" :decimals="2" />
                </x-admin.card>
            @endif
            <x-admin.card title="Where order lines are now" subtitle="Lines created in this period">
                <x-admin.bars :rows="$statusBreakdown" />
            </x-admin.card>
            <x-admin.card title="Latest orders" flush>
                <table class="adm-table">
                    <tbody>
                        @forelse ($recentOrders as $order)
                            <tr>
                                <td>
                                    @if (auth()->user()->can('orders.view'))
                                        <a href="{{ route('admin.orders.show', $order) }}" class="font-medium hover:text-accent">{{ $order->order_number }}</a>
                                    @else
                                        <span class="font-medium">{{ $order->order_number }}</span>
                                    @endif
                                    <p class="text-xs text-muted">{{ $order->buyer?->name ?? $order->guest_name ?? 'Guest' }} · {{ $order->created_at->diffForHumans() }}</p>
                                </td>
                                <td class="num">${{ number_format((float) $order->total, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td class="text-center text-xs text-muted">No orders yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </x-admin.card>
        </div>
    </div>

    <div class="space-y-4">
        <x-admin.card title="Needs you" subtitle="Work waiting on the team">
            @if ($queue)
                <ul class="-my-1 space-y-1">
                    @foreach ($queue as $row)
                        <li>
                            <a href="{{ $row['url'] }}" class="group flex items-center gap-3 rounded-xl p-2 transition hover:bg-surface-2">
                                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-accent/10 text-accent"><x-ui.icon :name="$row['icon']" class="w-4 h-4" /></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-[13px] font-semibold group-hover:text-accent">{{ $row['label'] }}</span>
                                    <span class="block truncate text-xs text-muted">{{ $row['hint'] }}</span>
                                </span>
                                <span class="text-lg font-bold tabular-nums">{{ $row['count'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @else
                <x-admin.empty title="You're all caught up" text="Nothing is waiting on the team right now." class="!py-6" />
            @endif
        </x-admin.card>

        <x-admin.card title="Marketplace health" :subtitle="'Last '.$days.' days'">
            @can('finance.view')
                <x-slot:actions><a href="{{ route('admin.insights.index', ['days' => $days]) }}" class="text-xs font-semibold text-accent hover:underline">All insights</a></x-slot:actions>
            @endcan
            <x-admin.health :rows="$health" />
        </x-admin.card>

        @if ($activity->isNotEmpty())
            <x-admin.card title="Recent admin activity">
                <ol class="space-y-3 border-l border-line pl-4">
                    @foreach ($activity as $entry)
                        <li class="relative text-[13px]">
                            <span class="absolute -left-[21px] top-1.5 h-2 w-2 rounded-full bg-accent ring-4 ring-surface"></span>
                            <p class="font-medium">{{ $entry->description }}</p>
                            <p class="text-xs text-muted">{{ $entry->causer?->name ?? 'System' }} · {{ $entry->created_at->diffForHumans() }}</p>
                        </li>
                    @endforeach
                </ol>
            </x-admin.card>
        @endif
    </div>
</div>
@endsection
