@extends('layouts.admin')

@section('title', 'Commission Ledger')

@section('content')
<div>
    <div class="flex justify-between items-center mb-4">
        <h1 class="text-xl font-bold">Commission Ledger</h1>
        <a href="{{ route('admin.finance.export', request()->query()) }}" class="bg-slate-900 text-white text-sm font-semibold rounded-lg px-4 py-2">
            Export CSV
        </a>
    </div>

    <div class="grid grid-cols-3 gap-4 mb-6">
        <div class="border rounded-xl p-4 bg-white"><p class="text-xs text-slate-500">Gross Volume</p><p class="text-xl font-bold">${{ number_format($totals->gross ?? 0, 2) }}</p></div>
        <div class="border rounded-xl p-4 bg-white"><p class="text-xs text-slate-500">Platform Commission</p><p class="text-xl font-bold text-emerald-600">${{ number_format($totals->commission ?? 0, 2) }}</p></div>
        <div class="border rounded-xl p-4 bg-white"><p class="text-xs text-slate-500">Seller Payouts</p><p class="text-xl font-bold">${{ number_format($totals->payouts ?? 0, 2) }}</p></div>
    </div>

    <table class="w-full text-sm border rounded-xl overflow-hidden bg-white">
        <thead class="bg-slate-100 text-left">
            <tr><th class="p-3">Order</th><th class="p-3">Seller</th><th class="p-3">Line Total</th><th class="p-3">Fee</th><th class="p-3">Payout</th><th class="p-3">Status</th></tr>
        </thead>
        <tbody>
            @forelse ($entries as $entry)
                <tr class="border-t">
                    <td class="p-3">{{ $entry->order->order_number }}</td>
                    <td class="p-3">{{ $entry->seller->sellerProfile?->business_name ?? '—' }}</td>
                    <td class="p-3">${{ number_format($entry->line_total, 2) }}</td>
                    <td class="p-3">${{ number_format($entry->platform_fee_amount, 2) }}</td>
                    <td class="p-3">${{ number_format($entry->seller_payout_amount, 2) }}</td>
                    <td class="p-3"><span class="px-2 py-1 rounded-full bg-slate-100 text-xs">{{ $entry->status->label() }}</span></td>
                </tr>
            @empty
                <tr><td colspan="6" class="p-6 text-center text-slate-400">No ledger entries in range.</td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="mt-4">{{ $entries->links() }}</div>
</div>
@endsection
