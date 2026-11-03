@extends('layouts.admin')

@section('title', 'Payouts')

@section('content')
<x-admin.header title="Payouts" subtitle="Money moving to sellers. Payouts run by themselves once an order is delivered." />

<div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4" data-enter>
    <x-admin.stat label="Delivered, not paid" :value="$readyTotal" prefix="$" :decimals="2" icon="clock" />
    <x-admin.stat label="Paid out, all time" :value="$releasedTotal" prefix="$" :decimals="2" icon="archive" />
    <x-admin.stat label="Stores on hold" :value="$held->count()" icon="shield" />
    <x-admin.stat label="Blocked payout alerts" :value="$blocked" icon="alert" :href="route('admin.alerts.index')" />
</div>

@if ($held->isNotEmpty())
    <x-admin.card title="Stores with payouts on hold" class="mb-4">
        <ul class="space-y-2 text-[13px]">
            @foreach ($held as $s)
                <li class="flex flex-wrap justify-between gap-2">@can('sellers.approve')<a href="{{ route('admin.sellers.show', $s) }}" class="font-medium hover:text-accent">{{ $s->business_name }}</a>@else<span class="font-medium">{{ $s->business_name }}</span>@endcan<span class="text-muted">{{ $s->payouts_held_reason }}</span></li>
            @endforeach
        </ul>
    </x-admin.card>
@endif

<x-admin.tabs :tabs="$tabs" param="tab" :current="$tab" />
<x-admin.filters :search="request('q', '')" placeholder="Store name"><input type="hidden" name="tab" value="{{ $tab }}"></x-admin.filters>

<x-admin.card flush>
    @if ($lines->isEmpty())
        <x-admin.empty icon="archive" title="Nothing here" />
    @else
        <table class="adm-table">
            <thead><tr><th>Order</th><th>Store</th><th>{{ $tab === 'released' ? 'Paid' : 'Delivered' }}</th><th class="num">Payout</th><th></th></tr></thead>
            <tbody>
                @foreach ($lines as $line)
                    <tr>
                        <td><a href="{{ route('admin.orders.show', $line->order) }}" class="font-medium hover:text-accent">{{ $line->order?->order_number }}</a><p class="max-w-[280px] truncate text-xs text-muted">{{ $line->product_title }}</p></td>
                        <td class="whitespace-nowrap">{{ $line->seller?->sellerProfile?->business_name ?? '—' }}
                            @if ($line->seller?->sellerProfile?->payouts_held)<x-admin.status value="held" label="On hold" />@endif
                            @if ($line->seller?->sellerProfile && ! $line->seller->sellerProfile->stripe_onboarding_complete)<x-admin.status value="off" label="No Stripe account" />@endif
                        </td>
                        <td class="whitespace-nowrap text-muted">{{ ($tab === 'released' ? $line->payout_released_at : $line->delivered_at)?->format('M j, Y') ?? '—' }}</td>
                        <td class="num">${{ number_format((float) $line->seller_payout_amount, 2) }}</td>
                        <td class="text-right">
                            @if ($tab === 'ready')
                                @can('refunds.issue')
                                    <x-admin.action :url="route('admin.finance.payouts.retry', $line)" label="Retry" size="xs" body="Tries this payout again. It can't pay twice, and holds still apply." />
                                @endcan
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-admin.card>
<div class="mt-4">{{ $lines->links() }}</div>
@endsection
