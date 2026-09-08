@extends('layouts.storefront')

@section('title', 'Payment — ' . config('app.name'))

@section('content')
<div class="container-x max-w-lg py-14" data-reveal>
    <div class="card p-6 sm:p-8">
        <h1 class="text-xl font-bold">Payment</h1>
        <p class="mt-1 text-sm text-muted">Order {{ $order->order_number }} · <span class="font-semibold text-fg">${{ number_format($order->total, 2) }}</span></p>

        <div id="payment-element" class="mt-6"></div>
        <button id="submit-payment" class="btn-accent mt-5 w-full !py-3.5 text-base">
            Pay ${{ number_format($order->total, 2) }}
        </button>
        <p id="payment-message" class="mt-2 text-sm text-rose-500"></p>
        <p class="mt-4 text-center text-xs text-muted"><x-ui.icon name="shield" class="mr-1 inline w-3.5 h-3.5" /> Secured by Stripe</p>
    </div>
</div>

@push('scripts')
<script src="https://js.stripe.com/v3/" @nonce></script>
<script @nonce>
    const stripe = Stripe(@json(config('services.stripe.key')));
    const elements = stripe.elements({ clientSecret: @json($clientSecret), appearance: { theme: 'stripe' } });
    const paymentElement = elements.create('payment');
    paymentElement.mount('#payment-element');

    document.getElementById('submit-payment').addEventListener('click', async () => {
        const { error } = await stripe.confirmPayment({
            elements,
            confirmParams: { return_url: @json(route('checkout.confirmation', $order->order_number)) },
        });
        if (error) document.getElementById('payment-message').textContent = error.message;
    });
</script>
@endpush
@endsection
