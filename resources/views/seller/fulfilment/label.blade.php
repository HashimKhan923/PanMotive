@extends('layouts.seller')
@section('title', 'Buy label — ' . $order->order_number)

@section('content')
<div class="max-w-xl">
    <div class="flex items-center gap-3">
        <a href="{{ route('seller.orders.show', $items->first()) }}" class="btn-ghost !p-2" aria-label="Back to order"><x-ui.icon name="arrow-left" class="w-6 h-6" /></a>
        <h1 class="text-2xl font-bold">Buy a shipping label</h1>
    </div>

    <div class="card mt-6 p-6">
        <p class="text-sm">
            <span class="font-semibold">{{ $order->order_number }}</span> · {{ $items->count() }} {{ Str::plural('item', $items->count()) }} · {{ number_format($weight, 1) }} lb total
        </p>
        <p class="mt-1 text-xs text-muted">One label covers all your items in this order.</p>

        @if (! $available)
            <div class="mt-4 rounded-xl border border-line bg-surface-2 p-4 text-sm">
                <p class="font-semibold">Label buying isn't switched on yet</p>
                <p class="mt-1 text-muted">Ship with your own carrier account, then add the tracking number on the order page — the buyer gets it right away.</p>
                <a href="{{ route('seller.orders.show', $items->first()) }}" class="btn-accent btn-sm mt-3">Add tracking</a>
            </div>
        @elseif (! $hasShipFrom)
            <div class="mt-4 rounded-xl border border-accent/30 bg-accent/10 p-4 text-sm">
                <p class="font-semibold">Add your ship-from address</p>
                <p class="mt-1 text-muted">Carriers need it for the label's return address.</p>
                <a href="{{ route('seller.settings.edit') }}" class="btn-accent btn-sm mt-3">Store settings</a>
            </div>
        @elseif ($error)
            <p class="mt-4 rounded-xl bg-rose-500/10 px-4 py-3 text-sm text-rose-600">{{ $error }}</p>
        @else
            <form method="POST" action="{{ route('seller.fulfilment.buy-label', $order) }}" class="mt-4 space-y-2">
                @csrf
                @foreach ($quote['rates'] as $rate)
                    <label class="flex cursor-pointer items-center justify-between rounded-xl border border-line p-3 hover:border-accent has-[:checked]:border-accent has-[:checked]:bg-accent/5">
                        <span class="flex items-center gap-3">
                            <input type="radio" name="rate_id" value="{{ $rate['id'] }}" @checked($loop->first) class="text-accent focus:ring-accent">
                            <span>
                                <span class="font-semibold">{{ $rate['carrier'] }}</span>
                                <span class="block text-xs text-muted">{{ $rate['service'] }}{{ $rate['days'] ? ' · '.$rate['days'].' '.Str::plural('day', $rate['days']) : '' }}</span>
                            </span>
                        </span>
                        <span class="text-right">
                            <span class="font-bold">${{ number_format($rate['rate'], 2) }}</span>
                            @if ($rate['retail'] && $rate['retail'] > $rate['rate'])
                                <span class="block text-xs text-muted line-through">${{ number_format($rate['retail'], 2) }} retail</span>
                            @endif
                        </span>
                    </label>
                @endforeach
                @error('label') <p class="text-xs text-rose-500">{{ $message }}</p> @enderror
                <p class="pt-1 text-xs text-muted">The postage is taken from this order's payout. Items are marked shipped with the carrier's real tracking number.</p>
                <button class="btn-accent mt-2 w-full">Buy label &amp; mark shipped</button>
            </form>
        @endif
    </div>
</div>
@endsection
