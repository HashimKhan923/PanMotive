@extends('layouts.storefront')

@section('title', 'Order ' . $order->order_number . ' — ' . config('app.name'))

@section('content')
<div class="container-x max-w-3xl py-10" data-reveal>
    <a href="{{ route('orders.index') }}" class="inline-flex items-center gap-1 text-sm text-muted hover:text-accent">
        <x-ui.icon name="chevron-right" class="w-4 h-4 rotate-180" /> All orders
    </a>

    <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">Order {{ $order->order_number }}</h1>
            <p class="mt-1 text-sm text-muted">Placed {{ optional($order->placed_at ?? $order->created_at)->format('M j, Y') }}</p>
        </div>
        <span class="pill !bg-accent/10 !text-accent">{{ $order->status->label() }}</span>
    </div>

    @if (session('success'))
        <p class="mt-4 rounded-xl bg-emerald-500/10 px-4 py-3 text-sm text-emerald-600">{{ session('success') }}</p>
    @endif

    <div class="mt-8 space-y-4">
        @foreach ($order->items as $item)
            <div class="card p-5">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="font-semibold">{{ $item->product_title }}</p>
                        <p class="mt-0.5 text-sm text-muted">Qty {{ $item->quantity }} · ${{ number_format($item->line_total, 2) }}</p>
                    </div>
                    <span class="pill">{{ $item->status->label() }}</span>
                </div>

                @if ($item->tracking_number)
                    <p class="mt-2 inline-flex items-center gap-1.5 text-sm">
                        <x-ui.icon name="truck" class="w-4 h-4 text-muted" /> {{ $item->carrier }} — <span class="font-mono">{{ $item->tracking_number }}</span>
                    </p>
                @endif

                @if (in_array($item->status->value, ['shipped', 'out_for_delivery'], true))
                    <div class="mt-4 flex flex-wrap gap-2">
                        <form method="POST" action="{{ route('orders.confirm-delivery', $item) }}">
                            @csrf
                            <button class="btn bg-emerald-500 text-white hover:bg-emerald-400 btn-sm">Confirm delivery</button>
                        </form>
                        <button type="button" onclick="document.getElementById('return-{{ $item->id }}').showModal()" class="btn-outline btn-sm">Request return</button>
                        <dialog id="return-{{ $item->id }}" class="card max-w-sm p-6 backdrop:bg-black/50">
                            <form method="POST" action="{{ route('orders.return', $item) }}" class="space-y-3">
                                @csrf
                                <h3 class="font-semibold">Request a return</h3>
                                <textarea name="reason" rows="3" placeholder="Tell us what's wrong" class="input text-sm" required></textarea>
                                <div class="flex gap-2">
                                    <button class="btn-brand btn-sm flex-1">Submit</button>
                                    <button type="button" onclick="this.closest('dialog').close()" class="btn-outline btn-sm">Cancel</button>
                                </div>
                            </form>
                        </dialog>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    <div class="mt-6 flex justify-between border-t border-line pt-4 text-lg font-bold">
        <span>Total</span><span>${{ number_format($order->total, 2) }}</span>
    </div>
</div>
@endsection
