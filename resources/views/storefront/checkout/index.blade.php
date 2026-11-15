@extends('layouts.storefront')

@section('title', 'Checkout — ' . config('app.name'))

@section('content')
<div class="container-x py-10" data-reveal>
    <h1 class="text-2xl font-bold sm:text-3xl">Checkout</h1>

    @if (session('status'))
        <p class="mt-4 rounded-xl bg-surface-2 px-4 py-3 text-sm text-fg">{{ session('status') }}</p>
    @endif

    @if ($lines->isEmpty())
        <div class="mt-8 card grid place-items-center p-16 text-center">
            <x-ui.icon name="cart" class="w-12 h-12 text-muted" />
            <p class="mt-3 font-semibold">Your cart is empty</p>
            <a href="{{ route('products.index') }}" class="btn-accent mt-4">Browse parts</a>
        </div>
    @else
        <div class="mt-8 grid gap-8 lg:grid-cols-[1fr_380px]">
            {{-- form --}}
            <form id="checkout-form" method="POST" action="{{ route('checkout.store') }}" class="space-y-5">
                @csrf

                @guest
                    <div class="card p-6">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <h2 class="font-bold">Contact</h2>
                            <a href="{{ route('login', ['redirect' => url()->current()]) }}" class="text-xs font-medium text-accent hover:underline">Log in for faster checkout</a>
                        </div>
                        <div class="mt-4">
                            <x-auth.google-button label="Continue with Google" :redirect="url()->current()" />
                            <label for="co-email" class="label">Email <span class="text-rose-500" aria-hidden="true">*</span></label>
                            <input id="co-email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required class="input @error('email') !border-rose-500 @enderror" placeholder="you@example.com">
                            <p class="mt-1 text-xs text-muted">We'll send your receipt and tracking here. No account needed.</p>
                            @error('email') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                        </div>
                    </div>
                @endguest

                <div class="card p-6">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h2 class="font-bold">Shipping address</h2>
                        <p class="text-xs text-muted"><span class="text-rose-500">*</span> Required</p>
                    </div>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        @foreach (['name' => 'Full name', 'line1' => 'Address', 'line2' => 'Apt, suite (optional)', 'city' => 'City', 'state' => 'State', 'postal_code' => 'ZIP code', 'phone' => 'Phone number'] as $field => $label)
                            @php($required = $field !== 'line2')
                            <div class="{{ in_array($field, ['name', 'line1', 'line2']) ? 'sm:col-span-2' : '' }}">
                                <label for="co-{{ $field }}" class="label">{{ $label }} @if ($required)<span class="text-rose-500" aria-hidden="true">*</span>@endif</label>
                                <input id="co-{{ $field }}" name="{{ $field }}" value="{{ old($field, $field === 'phone' ? auth()->user()?->phone : null) }}"
                                       autocomplete="{{ ['name'=>'name','line1'=>'address-line1','line2'=>'address-line2','city'=>'address-level2','state'=>'address-level1','postal_code'=>'postal-code','phone'=>'tel'][$field] }}"
                                       @if ($field === 'phone') type="tel" inputmode="tel" placeholder="(555) 123-4567" maxlength="30" @endif
                                       @if ($field === 'postal_code') inputmode="numeric" maxlength="10" @endif
                                       @required($required)
                                       class="input @error($field) !border-rose-500 @enderror">
                                @if ($field === 'phone')<p class="mt-1 text-xs text-muted">Only used by the carrier and seller if there's a problem with delivery.</p>@endif
                                @error($field) <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                            </div>
                        @endforeach
                        <div>
                            <label for="co-country" class="label">Country <span class="text-rose-500" aria-hidden="true">*</span></label>
                            <input id="co-country" name="country" value="{{ old('country', 'US') }}" maxlength="2" autocomplete="country" required class="input @error('country') !border-rose-500 @enderror">
                            @error('country') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                <button class="btn-accent w-full !py-3.5 text-base">Continue to payment <x-ui.icon name="arrow-right" class="w-4 h-4" /></button>
            </form>

            {{-- summary --}}
            <div class="space-y-4">
                @include('storefront.checkout.partials.fit-check')
                <div class="card p-6">
                    <h2 class="font-bold">Order summary</h2>

                    <div class="mt-4 space-y-4">
                        @foreach ($grouped as $group)
                            <div wire:key="cs-{{ $group['seller']?->id ?? $loop->index }}">
                                <p class="text-xs font-semibold uppercase tracking-wide text-muted">
                                    Sold by {{ $group['seller']?->sellerProfile?->business_name ?? 'Verified seller' }}
                                </p>
                                <ul class="mt-2 space-y-2 text-sm">
                                    @foreach ($group['lines'] as $line)
                                        <li class="flex justify-between gap-3">
                                            <span class="text-muted">{{ $line['product']->title }} <span class="text-line">×</span> {{ $line['quantity'] }}
                                                @if ($fit['vehicle'])
                                                    @if (app(\App\Services\FitGuarantee::class)->covers($line['product']))
                                                        <span class="mt-0.5 flex items-center gap-1 text-xs font-medium text-emerald-600"><x-ui.icon name="check" class="h-3.5 w-3.5" /> Fits · Fit Guarantee</span>
                                                    @else
                                                        <span class="mt-0.5 flex items-center gap-1 text-xs font-medium text-amber-600"><x-ui.icon name="info" class="h-3.5 w-3.5" /> Fit not confirmed</span>
                                                    @endif
                                                @endif
                                            </span>
                                            <span class="font-medium">${{ number_format($line['line_total'], 2) }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>

                    <hr class="my-4 border-line">
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between"><dt class="text-muted">Subtotal</dt><dd>${{ number_format($subtotal, 2) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-muted">Shipping</dt><dd>{{ $shippingFree ? 'FREE' : '$' . number_format($shipping, 2) }}</dd></div>
                        @if ($discount > 0)
                            <div class="flex justify-between text-emerald-600"><dt>Coupon ({{ $couponCode }})</dt><dd>−${{ number_format($discount, 2) }}</dd></div>
                        @endif
                        @foreach ($bundlesApplied as $b)
                            <div class="flex justify-between text-emerald-600"><dt>Bundle: {{ $b['title'] }}</dt><dd>−${{ number_format($b['savings'], 2) }}</dd></div>
                        @endforeach
                        @if ($taxCollecting)
                            <div class="flex justify-between"><dt class="text-muted">Sales tax</dt><dd class="text-muted">Added on the next step</dd></div>
                        @endif
                        <div class="flex justify-between border-t border-line pt-2 text-base font-bold">
                            <dt>Estimated total</dt>
                            <dd>${{ number_format(max(0, $subtotal + $shipping - $discount - $bundleSavings), 2) }}</dd>
                        </div>
                    </dl>
                    <p class="mt-2 text-xs text-muted">Credits, if applied, are deducted on the payment step.</p>
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

                <p class="text-center text-xs text-muted"><x-ui.icon name="shield" class="mr-1 inline w-4 h-4" /> Card handled by Stripe. We never see your card number.</p>
            </div>
        </div>
    @endif
</div>
@endsection
