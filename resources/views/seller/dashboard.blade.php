@extends('layouts.seller')

@section('title', 'Dashboard')

@section('content')
<div class="space-y-8">
    <h1 class="text-xl font-bold">Dashboard</h1>

    <div class="grid gap-4 sm:grid-cols-3 lg:grid-cols-5">
        @foreach ([
            'Active listings' => number_format($stats['active_listings']),
            'Pending orders' => number_format($stats['pending_orders']),
            'Lifetime sales' => '$' . number_format($stats['lifetime_sales'], 2),
            'Pending payout' => '$' . number_format($stats['pending_payout'], 2),
            'Released payout' => '$' . number_format($stats['released_payout'], 2),
        ] as $label => $value)
            <div class="rounded-xl border border-slate-200 bg-white p-4">
                <div class="text-xs uppercase tracking-wide text-slate-400">{{ $label }}</div>
                <div class="mt-1 text-2xl font-semibold">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    <div class="grid gap-8 lg:grid-cols-2">
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <h2 class="mb-3 text-sm font-semibold text-slate-500">Sales — last 30 days</h2>
            @if ($salesTrend->isEmpty())
                <p class="text-sm text-slate-400">No sales in this window.</p>
            @else
                <table class="w-full text-sm">
                    @foreach ($salesTrend as $row)
                        <tr class="border-b border-slate-100 last:border-0">
                            <td class="py-1.5 text-slate-500">{{ $row->date }}</td>
                            <td class="py-1.5 text-right font-medium">${{ number_format($row->total, 2) }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <h2 class="mb-3 text-sm font-semibold text-slate-500">Top products</h2>
            @if ($topProducts->isEmpty())
                <p class="text-sm text-slate-400">No products yet.</p>
            @else
                <ul class="space-y-2 text-sm">
                    @foreach ($topProducts as $product)
                        <li class="flex justify-between">
                            <a href="{{ route('seller.products.edit', $product) }}" class="text-amber-600 hover:underline">
                                {{ $product->title }}
                            </a>
                            <span class="text-slate-400">{{ $product->order_items_count }} sold</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
@endsection
