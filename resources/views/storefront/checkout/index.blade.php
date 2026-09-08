@extends('layouts.storefront')

@section('title', 'Checkout — ' . config('app.name'))

@section('content')
<div class="container-x py-10" data-reveal>
    <h1 class="text-2xl font-bold sm:text-3xl">Checkout</h1>

    @if (session('status'))
        <p class="mt-4 rounded-xl bg-accent/10 px-4 py-3 text-sm text-accent">{{ session('status') }}</p>
    @endif

    @if ($lines->isEmpty())
        <div class="mt-8 card grid place-items-center p-16 text-center">
            <x-ui.icon name="cart" class="w-10 h-10 text-muted" />
            <p class="mt-3 font-semibold">Your cart is empty</p>
            <a href="{{ route('products.index') }}" class="btn-accent mt-4">Browse parts</a>
        </div>
    @else
        <div class="mt-8 grid gap-8 lg:grid-cols-[1fr_380px]">
            {{-- form --}}
            <form id="checkout-form" method="POST" action="{{ route('checkout.store') }}" class="space-y-5">
                @csrf
                <div class="card p-6">
                    <h2 class="font-bold">Shipping address</h2>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        @foreach (['name' => 'Full name', 'line1' => 'Address', 'line2' => 'Apt, suite (optional)', 'city' => 'City', 'state' => 'State', 'postal_code' => 'ZIP code'] as $field => $label)
                            <div class="{{ in_array($field, ['name', 'line1', 'line2']) ? 'sm:col-span-2' : '' }}">
                                <label for="co-{{ $field }}" class="label">{{ $label }}</label>
                                <input id="co-{{ $field }}" name="{{ $field }}" value="{{ old($field) }}"
                                       autocomplete="{{ ['name'=>'name','line1'=>'address-line1','line2'=>'address-line2','city'=>'address-level2','state'=>'address-level1','postal_code'=>'postal-code'][$field] }}"
                                       class="input">
                                @error($field) <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                            </div>
                        @endforeach
                        <div>
                            <label for="co-country" class="label">Country</label>
                            <input id="co-country" name="country" value="{{ old('country', 'US') }}" maxlength="2" autocomplete="country" class="input">
                            @error('country') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                <button class="btn-accent w-full !py-3.5 text-base">Continue to payment <x-ui.icon name="arrow-right" class="w-4 h-4" /></button>
            </form>

            {{-- summary --}}
            <div class="space-y-4">
                <div class="card p-6">
                    <h2 class="font-bold">Order summary</h2>
                    <ul class="mt-4 space-y-3 text-sm">
                        @foreach ($lines as $line)
                            <li class="flex justify-between gap-3">
                                <span class="text-muted">{{ $line['product']->title }} <span class="text-line">×</span> {{ $line['quantity'] }}</span>
                                <span class="font-medium">${{ number_format($line['line_total'], 2) }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <hr class="my-4 border-line">
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between"><dt class="text-muted">Subtotal</dt><dd>${{ number_format($subtotal, 2) }}</dd></div>
                        @if ($discount > 0)
                            <div class="flex justify-between text-emerald-600"><dt>Coupon ({{ $couponCode }})</dt><dd>−${{ number_format($discount, 2) }}</dd></div>
                        @endif
                        <div class="flex justify-between border-t border-line pt-2 text-base font-bold"><dt>Estimated total</dt><dd>${{ number_format(max(0, $subtotal - $discount), 2) }}</dd></div>
                    </dl>
                </div>

                <div class="card p-6">
                    <h3 class="text-sm font-semibold">Promo &amp; credits</h3>
                    <form method="POST" action="{{ route('checkout.apply-coupon') }}" class="mt-3 flex gap-2">
                        @csrf
                        <input name="code" value="{{ $couponCode }}" placeholder="Coupon code" class="input h-10 text-sm">
                        <button class="btn-brand btn-sm">Apply</button>
                    </form>
                    @error('coupon') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                    @auth
                        <label class="mt-3 flex items-center gap-2 text-sm">
                            <input type="checkbox" name="use_credits" value="1" form="checkout-form" class="rounded text-accent focus:ring-accent">
                            Use credits (${{ number_format(auth()->user()->wallet_balance, 2) }} available · up to 50%)
                        </label>
                    @endauth
                </div>

                <p class="text-center text-xs text-muted"><x-ui.icon name="shield" class="mr-1 inline w-3.5 h-3.5" /> Card handled by Stripe. We never see your card number.</p>
            </div>
        </div>
    @endif
</div>
@endsection
