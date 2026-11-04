@extends('layouts.admin')

@section('title', 'Orders')

@section('content')
<x-admin.header title="All orders" subtitle="Every order on the marketplace. Open one to refund, cancel, or see its full history.">
    @can('finance.export')
        <a href="{{ route('admin.orders.export', request()->query()) }}" class="btn-outline btn-sm"><x-ui.icon name="download" class="w-4 h-4" /> Export CSV</a>
    @endcan
</x-admin.header>

<x-admin.tabs :tabs="$tabs" :current="$status" />

<x-admin.filters :search="request('q', '')" placeholder="Order number, buyer, or tracking number">
    @if ($status)<input type="hidden" name="status" value="{{ $status }}">@endif
    @if (request('seller'))<input type="hidden" name="seller" value="{{ request('seller') }}">@endif
    <input type="date" name="from" value="{{ request('from') }}" class="input h-9 !w-auto !py-0 text-[13px]" aria-label="From date">
    <input type="date" name="to" value="{{ request('to') }}" class="input h-9 !w-auto !py-0 text-[13px]" aria-label="To date">
</x-admin.filters>

<x-admin.card flush>
    @if ($orders->isEmpty())
        <x-admin.empty icon="list" title="No orders match" />
    @else
        <table class="adm-table">
            <thead><tr><th>Order</th><th>Buyer</th><th>Status</th><th class="num">Items</th><th class="num">Sellers</th><th>Date</th><th class="num">Total</th></tr></thead>
            <tbody>
                @foreach ($orders as $o)
                    <tr>
                        <td><a href="{{ route('admin.orders.show', $o) }}" class="font-medium hover:text-accent">{{ $o->order_number }}</a></td>
                        <td>{{ $o->buyer?->name ?? $o->guest_name ?? 'Guest' }}<p class="text-xs text-muted">{{ $o->buyer?->email ?? $o->guest_email }}</p></td>
                        <td><x-admin.status :value="$o->status" :label="$o->status->label()" /></td>
                        <td class="num">{{ $o->items_count }}</td>
                        <td class="num">{{ $o->items->pluck('seller_id')->unique()->count() }}</td>
                        <td class="whitespace-nowrap text-muted">{{ $o->created_at->format('M j, Y') }}</td>
                        <td class="num">${{ number_format((float) $o->total, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-admin.card>
<div class="mt-4">{{ $orders->links() }}</div>
@endsection
