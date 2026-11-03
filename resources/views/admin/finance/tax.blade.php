@extends('layouts.admin')

@section('title', 'Tax forms')

@section('content')
<x-admin.header title="Tax forms" :subtitle="'Sellers need a 1099-K above $'.number_format($threshold).' and '.number_format($minTxns).' orders in a year. Have your accountant confirm the current IRS rules before filing.'">
    <div class="flex rounded-xl border border-line bg-surface p-1">
        @foreach ($years as $y)
            <a href="{{ route('admin.finance.tax', ['year' => $y]) }}" @class(['adm-tab !py-1 !text-xs', 'is-active' => $y === $year])>{{ $y }}</a>
        @endforeach
    </div>
</x-admin.header>

<x-admin.card flush>
    @if ($rows->isEmpty())
        <x-admin.empty icon="list" title="No sales in {{ $year }}" />
    @else
        <table class="adm-table">
            <thead><tr><th>Store</th><th>1099-K</th><th class="num">Orders</th><th class="num">Gross payments</th></tr></thead>
            <tbody>
                @foreach ($rows as $r)
                    <tr>
                        <td class="font-medium">@can('sellers.approve')<a href="{{ route('admin.sellers.show', $r->slug) }}" class="hover:text-accent">{{ $r->name }}</a>@else{{ $r->name }}@endcan</td>
                        <td><x-admin.status :value="$r->reportable ? 'warning' : 'none'" :label="$r->reportable ? 'Form needed' : 'Not needed'" /></td>
                        <td class="num">{{ number_format((int) $r->txns) }}</td>
                        <td class="num">${{ number_format((float) $r->gross, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-admin.card>
@endsection
