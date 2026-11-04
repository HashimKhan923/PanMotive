@extends('layouts.admin')

@section('title', 'Seller ads')

@section('content')
<x-admin.header title="Seller ads" subtitle="Campaigns and boosts sellers are running on Panmotive. Stop anything that breaks policy." />

<div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4" data-enter>
    <x-admin.stat label="Live campaigns" :value="$stats['live']" icon="sparkles" />
    <x-admin.stat label="Campaign spend, all time" :value="$stats['spend']" prefix="$" :decimals="2" icon="dollar" />
    <x-admin.stat label="Live boosts" :value="$stats['boosts']" icon="chart" />
    <x-admin.stat label="Boost fees, all time" :value="$stats['boostFees']" prefix="$" :decimals="2" icon="dollar" />
</div>

<x-admin.filters :search="request('q', '')" placeholder="Campaign name" />

<x-admin.card title="Campaigns" flush class="mb-4">
    @if ($campaigns->isEmpty())
        <x-admin.empty icon="sparkles" title="No campaigns" />
    @else
        <table class="adm-table">
            <thead><tr><th>Campaign</th><th>Store</th><th>Status</th><th class="num">Ads</th><th class="num">Daily budget</th><th class="num">Spent</th><th></th></tr></thead>
            <tbody>
                @foreach ($campaigns as $c)
                    <tr>
                        <td class="font-medium">{{ $c->name }}</td>
                        <td class="whitespace-nowrap text-muted">{{ $c->seller?->sellerProfile?->business_name ?? '—' }}</td>
                        <td class="whitespace-nowrap"><x-admin.status :value="$c->status" />@if ($c->paused_for_credit)<x-admin.status value="warning" label="Out of credit" />@endif</td>
                        <td class="num">{{ $c->creatives_count }}</td>
                        <td class="num">${{ number_format((float) $c->daily_budget, 2) }}</td>
                        <td class="num">${{ number_format((float) $c->total_spend, 2) }}</td>
                        <td class="text-right">
                            @if (in_array($c->status, ['active', 'paused'], true))
                                <x-admin.action :url="route('admin.ads.campaigns.toggle', $c)" :label="$c->status === 'active' ? 'Pause' : 'Resume'" size="xs" reason :title="($c->status === 'active' ? 'Pause ' : 'Resume ').$c->name" />
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-admin.card>
<div class="mb-4">{{ $campaigns->links() }}</div>

<x-admin.card title="Live boosts" flush>
    @if ($boosts->isEmpty())
        <x-admin.empty icon="chart" title="No live boosts" />
    @else
        <table class="adm-table">
            <thead><tr><th>Listing</th><th>Store</th><th>Placement</th><th>Ends</th><th class="num">Fee</th><th></th></tr></thead>
            <tbody>
                @foreach ($boosts as $b)
                    <tr>
                        <td class="max-w-[300px] truncate font-medium">{{ $b->product?->title }}</td>
                        <td class="whitespace-nowrap text-muted">{{ $b->seller?->sellerProfile?->business_name ?? '—' }}</td>
                        <td class="capitalize text-muted">{{ $b->placement }}</td>
                        <td class="whitespace-nowrap text-muted">{{ $b->ends_at?->format('M j, Y') }}</td>
                        <td class="num">${{ number_format((float) $b->fee, 2) }}</td>
                        <td class="text-right"><x-admin.action :url="route('admin.ads.boosts.end', $b)" label="End" tone="danger" size="xs" reason title="End this boost" body="It stops showing now. The fee is not refunded automatically." /></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-admin.card>
@endsection
