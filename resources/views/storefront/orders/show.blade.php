@extends('layouts.storefront')

@section('title', 'Order ' . $order->order_number)

@section('content')
<div class="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:px-8">
    <a href="{{ route('orders.index') }}" class="text-sm text-slate-500 hover:text-slate-900">&larr; All orders</a>

    <h1 class="mt-2 text-2xl font-semibold">Order {{ $order->order_number }}</h1>
    <p class="mt-1 text-sm text-slate-500">Placed {{ optional($order->placed_at ?? $order->created_at)->format('M j, Y') }} · {{ $order->status->label() }}</p>

    @if (session('success'))
        <p class="mt-4 rounded-md bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('success') }}</p>
    @endif

    <div class="mt-8 space-y-3">
        @foreach ($order->items as $item)
            <div class="rounded-xl border border-slate-200 p-4">
                <p class="font-medium">{{ $item->product_title }}</p>
                <p class="text-sm text-slate-500">Status: {{ $item->status->label() }}</p>

                @if ($item->tracking_number)
                    <p class="text-sm">Tracking: {{ $item->carrier }} — {{ $item->tracking_number }}</p>
                @endif

                @if (in_array($item->status->value, ['shipped', 'out_for_delivery'], true))
                    <div class="mt-3 flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('orders.confirm-delivery', $item) }}">
                            @csrf
                            <button class="rounded-lg bg-emerald-500 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-400">
                                Confirm Delivery
                            </button>
                        </form>
                        <button type="button"
                                onclick="document.getElementById('return-{{ $item->id }}').showModal()"
                                class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50">
                            Request return
                        </button>
                        <dialog id="return-{{ $item->id }}" class="rounded-xl p-6">
                            <form method="POST" action="{{ route('orders.return', $item) }}" class="w-72 space-y-3">
                                @csrf
                                <textarea name="reason" rows="3" placeholder="Reason for return"
                                          class="w-full rounded-lg border-slate-300 text-sm" required></textarea>
                                <button class="w-full rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white">Submit</button>
                            </form>
                        </dialog>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    <div class="mt-6 flex justify-between border-t border-slate-200 pt-4 font-semibold">
        <span>Total</span>
        <span>${{ number_format($order->total, 2) }}</span>
    </div>
</div>
@endsection
