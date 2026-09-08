@extends('layouts.storefront')

@section('title', 'Your orders')

@section('content')
<div class="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:px-8">
    <h1 class="text-2xl font-semibold">Your orders</h1>

    @forelse ($orders as $order)
        <a href="{{ route('orders.show', $order) }}"
           class="mt-4 flex items-center justify-between rounded-xl border border-slate-200 p-4 hover:bg-slate-50">
            <div>
                <p class="font-medium">{{ $order->order_number }}</p>
                <p class="text-sm text-slate-500">{{ $order->items->count() }} item(s) · {{ $order->status->label() }}</p>
            </div>
            <span class="font-semibold">${{ number_format($order->total, 2) }}</span>
        </a>
    @empty
        <div class="mt-8 rounded-lg border border-dashed border-slate-300 p-10 text-center text-slate-500">
            No orders yet.
        </div>
    @endforelse

    <div class="mt-6">{{ $orders->links() }}</div>
</div>
@endsection
