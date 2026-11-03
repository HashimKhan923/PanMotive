@extends('layouts.storefront')

@section('title', 'Order ' . $order->order_number . ' — ' . config('app.name'))

@section('content')
@php
    use App\Enums\OrderStatus;
    $flow = [OrderStatus::Paid, OrderStatus::SellerAccepted, OrderStatus::Shipped, OrderStatus::OutForDelivery, OrderStatus::Delivered];
    $rank = fn ($status) => match ($status) {
        OrderStatus::Placed, OrderStatus::Paid => 1,
        OrderStatus::SellerAccepted => 2,
        OrderStatus::Shipped => 3,
        OrderStatus::OutForDelivery => 4,
        OrderStatus::Delivered, OrderStatus::PayoutReleased => 5,
        default => 0,
    };
@endphp

<div class="container-x max-w-3xl py-10">
    <a href="{{ route('track.form') }}" class="text-sm text-muted hover:text-accent">&larr; Track another order</a>

    <div class="mt-3 flex flex-wrap items-end justify-between gap-3" data-reveal>
        <div>
            <h1 class="text-2xl font-bold">Order {{ $order->order_number }}</h1>
            <p class="mt-1 text-sm text-muted">Placed {{ optional($order->placed_at ?? $order->created_at)->format('M j, Y') }} · {{ $order->contactName() }}</p>
        </div>
        <span class="pill !bg-brand/10 !text-brand dark:!text-brand-fg">{{ $order->status->label() }}</span>
    </div>

    <div class="mt-6 space-y-5">
        @foreach ($order->items as $item)
            @php $done = $rank($item->status); @endphp
            <div wire:key="ti-{{ $item->id }}" class="card p-5" data-reveal>
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="font-semibold">{{ $item->product_title }}</p>
                        <p class="mt-0.5 text-sm text-muted">Qty {{ $item->quantity }} · ${{ number_format($item->line_total, 2) }}</p>
                    </div>
                    <span class="pill">{{ $item->status->label() }}</span>
                </div>

                @if ($item->tracking_number)
                    <p class="mt-3 text-sm">
                        <span class="text-muted">Carrier:</span> {{ $item->carrier ?: 'Carrier' }}
                        <span class="ml-3 text-muted">Tracking:</span> <span class="font-mono">{{ $item->tracking_number }}</span>
                    </p>
                @endif

                <div class="mt-4 flex items-center">
                    @foreach ($flow as $i => $stage)
                        <div class="flex items-center {{ $i < count($flow) - 1 ? 'flex-1' : '' }}">
                            <div class="grid h-7 w-7 shrink-0 place-items-center rounded-full text-xs font-bold
                                        {{ $done > $i ? 'bg-accent text-accent-fg' : 'bg-surface-2 text-muted' }}">
                                @if ($done > $i)<x-ui.icon name="check" class="w-3.5 h-3.5" />@else{{ $i + 1 }}@endif
                            </div>
                            @if ($i < count($flow) - 1)
                                <div class="mx-1 h-0.5 flex-1 {{ $done > $i + 1 ? 'bg-accent' : 'bg-surface-2' }}"></div>
                            @endif
                        </div>
                    @endforeach
                </div>
                <div class="mt-1.5 flex justify-between text-[10px] uppercase tracking-wide text-muted">
                    <span>Paid</span><span>Accepted</span><span>Shipped</span><span>Out</span><span>Delivered</span>
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-6 card p-5 text-sm" data-reveal>
        <div class="flex justify-between"><span class="text-muted">Subtotal</span><span>${{ number_format($order->subtotal, 2) }}</span></div>
        @if ($order->shipping_total > 0)
            <div class="mt-1 flex justify-between"><span class="text-muted">Shipping</span><span>${{ number_format($order->shipping_total, 2) }}</span></div>
        @endif
        @if ($order->discount_total > 0)
            <div class="mt-1 flex justify-between text-emerald-600 dark:text-emerald-400"><span>Discount</span><span>-${{ number_format($order->discount_total, 2) }}</span></div>
        @endif
        <div class="mt-2 flex justify-between border-t border-line pt-2 font-bold"><span>Total</span><span>${{ number_format($order->total, 2) }}</span></div>
    </div>
</div>
@endsection
