@extends('layouts.admin')

@section('title', 'Supply Network')

@section('content')
<x-admin.header title="Supply Network" subtitle="Stores reselling other stores' parts. The supplier ships and is paid; the reseller earns its margin after delivery." />

<div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3" data-enter>
    <x-admin.stat label="Resold sales" :value="$stats['sales']" prefix="$" :decimals="2" icon="dollar" />
    <x-admin.stat label="Reseller margins" :value="$stats['margins']" prefix="$" :decimals="2" icon="chart" />
    <x-admin.stat label="Live resold listings" :value="$stats['listings']" icon="box" />
</div>

<x-admin.tabs :tabs="$tabs" :current="$status" />

<x-admin.card flush class="mb-4">
    @if ($connections->isEmpty())
        <x-admin.empty icon="users" title="No partnerships here" />
    @else
        <table class="adm-table">
            <thead><tr><th>Supplier</th><th>Reseller</th><th>Status</th><th class="num">Margin</th><th>Since</th><th></th></tr></thead>
            <tbody>
                @foreach ($connections as $c)
                    <tr>
                        <td>{{ $c->supplier?->sellerProfile?->business_name ?? '—' }}</td>
                        <td>{{ $c->reseller?->sellerProfile?->business_name ?? '—' }}</td>
                        <td><x-admin.status :value="$c->status === 'approved' ? 'active' : $c->status" /></td>
                        <td class="num">{{ rtrim(rtrim(number_format((float) $c->margin_pct, 2), '0'), '.') }}%</td>
                        <td class="whitespace-nowrap text-muted">{{ ($c->decided_at ?? $c->created_at)->format('M j, Y') }}</td>
                        <td class="whitespace-nowrap text-right">
                            @if (in_array($c->status, ['pending', 'approved'], true))
                                <x-admin.action :url="route('admin.supply.end', $c)" label="End" tone="danger" size="xs" reason title="End this partnership" body="The reseller's copies stop being for sale. Orders already placed still ship and pay out." confirm="End" />
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-admin.card>
<div class="mb-4">{{ $connections->links() }}</div>

<x-admin.card title="Latest resold order lines" flush>
    @if ($recent->isEmpty())
        <x-admin.empty title="No resold orders yet" class="!py-8" />
    @else
        <table class="adm-table">
            <thead><tr><th>Order</th><th>Item</th><th>Ships from</th><th>Sold through</th><th>Status</th><th class="num">Sale</th><th class="num">Margin</th></tr></thead>
            <tbody>
                @foreach ($recent as $i)
                    <tr>
                        <td class="whitespace-nowrap">@if ($i->order && auth()->user()->can('orders.view'))<a href="{{ route('admin.orders.show', $i->order) }}" class="hover:text-accent">{{ $i->order->order_number }}</a>@else{{ $i->order?->order_number }}@endif</td>
                        <td>{{ \Illuminate\Support\Str::limit($i->product_title, 50) }}</td>
                        <td class="text-muted">{{ $i->seller?->sellerProfile?->business_name }}</td>
                        <td class="text-muted">{{ $i->reseller?->sellerProfile?->business_name }}</td>
                        <td><x-admin.status :value="$i->status->value" :label="$i->status->label()" /></td>
                        <td class="num">${{ number_format((float) $i->line_total, 2) }}</td>
                        <td class="num">${{ number_format((float) $i->reseller_margin, 2) }}<p class="text-xs text-muted">{{ $i->reseller_paid_at ? 'paid' : 'not paid yet' }}</p></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-admin.card>
@endsection
