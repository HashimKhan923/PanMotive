@extends('layouts.admin')

@section('title', 'Dashboard')

@section('content')
<div class="space-y-6">
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        @foreach ([
            ['GMV (30d)', '$' . number_format($stats['gmv_30d'], 2), 'text-slate-900'],
            ['Commission Revenue (30d)', '$' . number_format($stats['commission_revenue_30d'], 2), 'text-emerald-600'],
            ['Active Sellers', $stats['active_sellers'], 'text-slate-900'],
            ['Pending Approvals', $stats['pending_seller_approvals'], 'text-amber-600'],
            ['Orders Today', $stats['orders_today'], 'text-slate-900'],
            ['New Buyers (30d)', $stats['new_buyers_30d'], 'text-slate-900'],
            ['Open Disputes', $stats['open_disputes'], 'text-red-600'],
            ['Blocked Payouts', $stats['blocked_payouts'], 'text-red-600'],
        ] as [$label, $value, $color])
            <div class="border rounded-xl p-4 bg-white" data-reveal>
                <p class="text-xs text-slate-500">{{ $label }}</p>
                <p class="text-2xl font-bold {{ $color }} mt-1">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    <div class="border rounded-xl p-4 bg-white">
        <h3 class="font-semibold mb-3">GMV — Last 30 Days</h3>
        <canvas id="gmvChart" height="80"></canvas>
    </div>

    <div class="grid md:grid-cols-2 gap-4">
        <div class="border rounded-xl p-4 bg-white">
            <h3 class="font-semibold mb-3">Conversion (30d)</h3>
            <ul class="text-sm space-y-2">
                <li class="flex justify-between"><span>Orders Placed</span><strong>{{ $conversionFunnel['orders_placed'] }}</strong></li>
                <li class="flex justify-between"><span>Orders Paid</span><strong>{{ $conversionFunnel['orders_paid'] }}</strong></li>
            </ul>
        </div>
        <div class="border rounded-xl p-4 bg-white">
            <h3 class="font-semibold mb-3">Top Categories (30d)</h3>
            <ul class="text-sm space-y-2">
                @forelse ($topCategories as $cat)
                    <li class="flex justify-between"><span>{{ $cat->name }}</span><strong>${{ number_format($cat->revenue, 2) }}</strong></li>
                @empty
                    <li class="text-slate-400">No sales yet.</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4" @nonce></script>
<script @nonce>
    new Chart(document.getElementById('gmvChart'), {
        type: 'line',
        data: {
            labels: @json($gmvTrend->pluck('date')),
            datasets: [{ label: 'GMV', data: @json($gmvTrend->pluck('total')), borderColor: '#f59e0b', tension: 0.3 }]
        },
        options: { plugins: { legend: { display: false } } }
    });
</script>
@endpush
@endsection
