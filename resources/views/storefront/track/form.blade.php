@extends('layouts.storefront')

@section('title', 'Track your order — ' . config('app.name'))

@section('content')
<div class="container-x max-w-lg py-12 lg:py-16">
    <div class="text-center" data-reveal>
        <span class="pill !bg-surface-2 !text-fg"><x-ui.icon name="truck" class="w-4 h-4" /> Order tracking</span>
        <h1 class="mt-3 text-2xl font-bold sm:text-3xl">Where's my order?</h1>
        <p class="mt-1 text-sm text-muted">Enter your order number and the email used at checkout.</p>
    </div>

    <form method="POST" action="{{ route('track.lookup') }}" class="card mt-8 space-y-4 p-6" data-reveal>
        @csrf
        <div>
            <label class="label" for="order_number">Order number</label>
            <input id="order_number" name="order_number" value="{{ old('order_number') }}" required
                   class="input mt-1" placeholder="PM-XXXXXX" autocomplete="off">
            @error('order_number') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="label" for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required
                   class="input mt-1" placeholder="you@example.com" autocomplete="email">
            @error('email') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
        </div>
        <button class="btn-accent w-full">Track order</button>
        @auth
            <p class="text-center text-xs text-muted">Signed in? <a href="{{ route('orders.index') }}" class="text-fg underline underline-offset-2">See all your orders</a>.</p>
        @endauth
    </form>
</div>
@endsection
