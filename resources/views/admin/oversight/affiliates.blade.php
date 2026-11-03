@extends('layouts.admin')

@section('title', 'Affiliates')

@section('content')
<x-admin.header title="Affiliates" subtitle="People earning commission for sending customers to seller stores. Stop anyone abusing the program." />

<div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3" data-enter>
    <x-admin.stat label="Commission earned" :value="$stats['earned']" prefix="$" :decimals="2" icon="dollar" />
    <x-admin.stat label="Paid out" :value="$stats['paid']" prefix="$" :decimals="2" icon="archive" />
    <x-admin.stat label="Link clicks" :value="$stats['clicks']" icon="external" />
</div>

<x-admin.tabs :tabs="$tabs" :current="$status" />
<x-admin.filters :search="request('q', '')" placeholder="Affiliate name or email">@if ($status)<input type="hidden" name="status" value="{{ $status }}">@endif</x-admin.filters>

<x-admin.card flush class="mb-4">
    @if ($affiliates->isEmpty())
        <x-admin.empty icon="users" title="No affiliates here" />
    @else
        <table class="adm-table">
            <thead><tr><th>Affiliate</th><th>Store</th><th>Status</th><th class="num">Clicks</th><th class="num">Sales</th><th class="num">Earned</th><th></th></tr></thead>
            <tbody>
                @foreach ($affiliates as $a)
                    <tr>
                        <td>{{ $a->name }}<p class="text-xs text-muted">{{ $a->email }} · {{ $a->code }}</p></td>
                        <td class="whitespace-nowrap text-muted">{{ $a->program?->seller?->sellerProfile?->business_name ?? '—' }}</td>
                        <td><x-admin.status :value="$a->status === 'rejected' ? 'removed' : $a->status" /></td>
                        <td class="num">{{ number_format((int) $a->clicks) }}</td>
                        <td class="num">{{ number_format((int) $a->conversions) }}</td>
                        <td class="num">${{ number_format((float) $a->earnings, 2) }}</td>
                        <td class="whitespace-nowrap text-right">
                            @if ($a->status !== 'approved')
                                <x-admin.action :url="route('admin.affiliates.status', $a)" label="Approve" size="xs" reason :title="'Approve '.$a->name"><input type="hidden" name="status" value="approved"></x-admin.action>
                            @endif
                            @if ($a->status !== 'rejected')
                                <x-admin.action :url="route('admin.affiliates.status', $a)" label="Remove" tone="danger" size="xs" reason :title="'Remove '.$a->name" body="Their links stop earning commission." confirm="Remove"><input type="hidden" name="status" value="rejected"></x-admin.action>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-admin.card>
<div class="mb-4">{{ $affiliates->links() }}</div>

<x-admin.card title="Pending commission" subtitle="Not yet approved, so it can still be reversed." flush>
    @if ($conversions->isEmpty())
        <x-admin.empty title="Nothing pending" class="!py-8" />
    @else
        <table class="adm-table">
            <thead><tr><th>Affiliate</th><th>Order</th><th class="num">Order value</th><th class="num">Commission</th><th></th></tr></thead>
            <tbody>
                @foreach ($conversions as $c)
                    <tr>
                        <td>{{ $c->affiliate?->name }}</td>
                        <td>@if ($c->order && auth()->user()->can('orders.view'))<a href="{{ route('admin.orders.show', $c->order) }}" class="hover:text-accent">{{ $c->order->order_number }}</a>@else{{ $c->order?->order_number }}@endif</td>
                        <td class="num">${{ number_format((float) $c->order_value, 2) }}</td>
                        <td class="num">${{ number_format((float) $c->commission, 2) }}</td>
                        <td class="text-right"><x-admin.action :url="route('admin.affiliates.void', $c)" label="Reverse" tone="danger" size="xs" reason title="Reverse this commission" /></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-admin.card>
@endsection
