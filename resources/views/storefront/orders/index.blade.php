@extends('layouts.storefront')

@section('title', 'Your orders — ' . config('app.name'))

@section('content')
<div class="container-x max-w-3xl py-10" data-reveal>
    <h1 class="text-2xl font-bold sm:text-3xl">Your orders</h1>

    <div class="mt-6 space-y-3" data-reveal-group>
        @forelse ($orders as $order)
            <a href="{{ route('orders.show', $order) }}" class="card flex items-center justify-between p-5 transition-shadow hover:shadow-card-hover">
                <div>
                    <p class="font-semibold">{{ $order->order_number }}</p>
                    <p class="text-sm text-muted">
                        {{ optional($order->placed_at ?? $order->created_at)->format('M j, Y') }} ·
                        {{ $order->items->count() }} {{ Str::plural('item', $order->items->count()) }} ·
                        <span class="font-medium text-fg">{{ $order->status->label() }}</span>
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <span class="font-bold">${{ number_format($order->total, 2) }}</span>
                    <x-ui.icon name="chevron-right" class="w-5 h-5 text-muted" />
                </div>
            </a>
        @empty
            <div class="card grid place-items-center p-16 text-center">
                <x-ui.icon name="box" class="w-10 h-10 text-muted" />
                <p class="mt-3 font-semibold">No orders yet</p>
                <a href="{{ route('products.index') }}" class="btn-accent mt-4">Start shopping</a>
            </div>
        @endforelse
    </div>

    @if ($orders->hasPages())<div class="mt-6">{{ $orders->links() }}</div>@endif
</div>
@endsection
