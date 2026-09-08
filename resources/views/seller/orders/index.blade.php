@extends('layouts.seller')

@section('title', 'Orders')

@section('content')
<div>
    <h1 class="text-xl font-bold mb-4">Orders</h1>

    @if (session('success'))
        <p class="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('success') }}</p>
    @endif

    <table class="w-full text-sm border rounded-xl overflow-hidden">
        <thead class="bg-slate-100 text-left">
            <tr>
                <th class="p-3">Order #</th>
                <th class="p-3">Product</th>
                <th class="p-3">Qty</th>
                <th class="p-3">Payout</th>
                <th class="p-3">Status</th>
                <th class="p-3">Action</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($items as $item)
                <tr class="border-t">
                    <td class="p-3">{{ $item->order->order_number }}</td>
                    <td class="p-3">{{ $item->product_title }}</td>
                    <td class="p-3">{{ $item->quantity }}</td>
                    <td class="p-3">${{ number_format($item->seller_payout_amount, 2) }}</td>
                    <td class="p-3">
                        <span class="px-2 py-1 rounded-full text-xs bg-slate-100">{{ $item->status->label() }}</span>
                    </td>
                    <td class="p-3">
                        @if ($item->status->value === 'placed')
                            <form method="POST" action="{{ route('seller.orders.accept', $item) }}">
                                @csrf
                                <button class="text-amber-600 font-medium">Accept</button>
                            </form>
                        @elseif ($item->status->value === 'seller_accepted')
                            <button onclick="document.getElementById('ship-modal-{{ $item->id }}').showModal()" class="text-amber-600 font-medium">
                                Mark Shipped
                            </button>
                            <dialog id="ship-modal-{{ $item->id }}" class="rounded-xl p-6">
                                <form method="POST" action="{{ route('seller.orders.ship', $item) }}" class="space-y-3 w-72">
                                    @csrf
                                    <input name="carrier" placeholder="Carrier (e.g. USPS)" class="w-full rounded-lg border-slate-300" required>
                                    <input name="tracking_number" placeholder="Tracking Number" class="w-full rounded-lg border-slate-300" required>
                                    <button class="bg-amber-500 text-slate-900 font-semibold rounded-lg px-4 py-2 w-full">Confirm Shipment</button>
                                </form>
                            </dialog>
                        @else
                            <span class="text-xs text-slate-400">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="p-6 text-center text-slate-400">No orders yet.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="mt-4">{{ $items->links() }}</div>
</div>
@endsection
