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
        <div class="mt-3 flex justify-between border-t border-slate-200 pt-3 font-semibold">
            <span>Total</span>
            <span>${{ number_format($subtotal, 2) }}</span>
        </div>

        <form method="POST" action="{{ route('checkout.store') }}" class="mt-8 space-y-4">
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
