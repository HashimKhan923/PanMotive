@extends('layouts.admin')

@section('title', 'Commission ledger')

@section('content')
<x-admin.header title="Commission ledger" subtitle="Delivered and paid-out order lines, with what each one earned.">
    @can('finance.export')
        <a href="{{ route('admin.finance.export', request()->query()) }}" class="btn-outline btn-sm"><x-ui.icon name="download" class="w-3.5 h-3.5" /> Export CSV</a>
    @endcan
</x-admin.header>

<x-admin.filters>
    <input type="date" name="from" value="{{ request('from') }}" class="input h-9 !w-auto !py-0 text-[13px]" aria-label="From date">
    <input type="date" name="to" value="{{ request('to') }}" class="input h-9 !w-auto !py-0 text-[13px]" aria-label="To date">
    <select name="seller_id" class="input h-9 !w-auto !py-0 text-[13px]" aria-label="Seller">
        <option value="">All sellers</option>
        @foreach ($sellers as $s)<option value="{{ $s->user_id }}" @selected((string) request('seller_id') === (string) $s->user_id)>{{ $s->business_name }}</option>@endforeach
    </select>
</x-admin.filters>

<div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3" data-enter>
    <x-admin.stat label="Gross" :value="(float) $totals->gross" prefix="$" :decimals="2" icon="dollar" />
    <x-admin.stat label="Commission" :value="(float) $totals->commission" prefix="$" :decimals="2" icon="chart" />
    <x-admin.stat label="Seller payouts" :value="(float) $totals->payouts" prefix="$" :decimals="2" icon="archive" />
</div>

<x-admin.card flush>
    @if ($entries->isEmpty())
        <x-admin.empty icon="list" title="No lines match" />
    @else
        <table class="adm-table">
            <thead><tr><th>Order</th><th>Seller</th><th>Status</th><th>Date</th><th class="num">Line</th><th class="num">Rate</th><th class="num">Commission</th><th class="num">Payout</th></tr></thead>
            <tbody>
                @foreach ($entries as $e)
                    <tr>
                        <td><a href="{{ route('admin.orders.show', $e->order) }}" class="font-medium hover:text-accent">{{ $e->order->order_number }}</a></td>
                        <td class="whitespace-nowrap text-muted">{{ $e->seller?->sellerProfile?->business_name ?? '—' }}</td>
                        <td><x-admin.status :value="$e->status" :label="$e->status->label()" /></td>
                        <td class="whitespace-nowrap text-muted">{{ $e->created_at->format('M j, Y') }}</td>
                        <td class="num">${{ number_format((float) $e->line_total, 2) }}</td>
                        <td class="num">{{ rtrim(rtrim(number_format((float) $e->commission_rate, 2), '0'), '.') }}%</td>
                        <td class="num">${{ number_format((float) $e->platform_fee_amount, 2) }}</td>
                        <td class="num">${{ number_format((float) $e->seller_payout_amount, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-admin.card>
<div class="mt-4">{{ $entries->links() }}</div>
@endsection
