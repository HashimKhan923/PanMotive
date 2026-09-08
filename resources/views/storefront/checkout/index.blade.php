@extends('layouts.storefront')

@section('title', 'Checkout')

@section('content')
<div class="mx-auto max-w-2xl px-4 py-12 sm:px-6 lg:px-8">
    <h1 class="text-2xl font-semibold">Checkout</h1>

    @if (session('status'))
        <p class="mt-4 rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ session('status') }}</p>
    @endif

    @if ($lines->isEmpty())
        <div class="mt-8 rounded-lg border border-dashed border-slate-300 p-8 text-center text-slate-500">
            Your cart is empty. <a href="{{ route('products.index') }}" class="underline">Browse the shop</a>.
        </div>
    @else
        <ul class="mt-8 divide-y divide-slate-100 text-sm">
            @foreach ($lines as $line)
                <li class="flex justify-between py-2">
                    <span>{{ $line['product']->title }} &times; {{ $line['quantity'] }}</span>
                    <span>${{ number_format($line['line_total'], 2) }}</span>
                </li>
            @endforeach
        </ul>
        <dl class="mt-3 space-y-1 border-t border-slate-200 pt-3 text-sm">
            <div class="flex justify-between"><dt>Subtotal</dt><dd>${{ number_format($subtotal, 2) }}</dd></div>
            @if ($discount > 0)
                <div class="flex justify-between text-emerald-600"><dt>Coupon ({{ $couponCode }})</dt><dd>-${{ number_format($discount, 2) }}</dd></div>
            @endif
            <div class="flex justify-between font-semibold"><dt>Estimated total</dt><dd>${{ number_format(max(0, $subtotal - $discount), 2) }}</dd></div>
        </dl>

        <div class="mt-6 border rounded-xl p-4">
            <form method="POST" action="{{ route('checkout.apply-coupon') }}" class="flex gap-2">
                @csrf
                <input name="code" value="{{ $couponCode }}" placeholder="Coupon code"
                       class="flex-1 rounded-lg border-slate-300 text-sm">
                <button class="bg-slate-900 text-white text-sm font-semibold rounded-lg px-4">Apply</button>
            </form>
            @error('coupon') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror

            @auth
                <label class="flex items-center gap-2 mt-3 text-sm">
                    <input type="checkbox" name="use_credits" value="1" form="checkout-form">
                    Use available credits (${{ number_format(auth()->user()->wallet_balance, 2) }} available, up to 50% of order)
                </label>
            @endauth
        </div>

        <form id="checkout-form" method="POST" action="{{ route('checkout.store') }}" class="mt-6 space-y-4">
            @csrf
            <h2 class="text-sm font-semibold text-slate-500">Shipping address</h2>

            @foreach (['name' => 'Full name', 'line1' => 'Address', 'line2' => 'Address line 2 (optional)', 'city' => 'City', 'state' => 'State', 'postal_code' => 'ZIP / Postal code'] as $field => $label)
                <div>
                    <label class="text-sm font-medium">{{ $label }}</label>
                    <input name="{{ $field }}" value="{{ old($field) }}" class="mt-1 w-full rounded-lg border-slate-300">
                    @error($field) <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>
            @endforeach

            <div>
                <label class="text-sm font-medium">Country (2-letter code)</label>
                <input name="country" value="{{ old('country', 'US') }}" maxlength="2" class="mt-1 w-full rounded-lg border-slate-300">
                @error('country') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
            </div>

            <button class="w-full rounded-lg bg-slate-900 px-5 py-3 text-white hover:bg-slate-700">
                Continue to payment
            </button>
        </form>
    @endif
</div>
@endsection
