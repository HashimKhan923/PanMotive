@extends('layouts.storefront')

@section('title', 'Payment')

@section('content')
<div class="max-w-lg mx-auto py-12 px-4">
    <h1 class="text-xl font-bold mb-4">Payment</h1>
    <p class="text-sm text-slate-500 mb-4">Order {{ $order->order_number }}</p>

    <div id="payment-element" class="mb-4"></div>
    <button id="submit-payment" class="bg-amber-500 hover:bg-amber-400 text-slate-900 font-semibold rounded-lg px-6 py-3 w-full transition">
        Pay ${{ number_format($order->total, 2) }}
    </button>
    <p id="payment-message" class="text-red-500 text-sm mt-2"></p>
</div>

@push('scripts')
<script src="https://js.stripe.com/v3/" @nonce></script>
<script @nonce>
    const stripe = Stripe(@json(config('services.stripe.key')));
    const elements = stripe.elements({ clientSecret: @json($clientSecret) });
    const paymentElement = elements.create('payment');
    paymentElement.mount('#payment-element');

    document.getElementById('submit-payment').addEventListener('click', async () => {
        const { error } = await stripe.confirmPayment({
            elements,
            confirmParams: {
                return_url: @json(route('checkout.confirmation', $order->order_number)),
            },
        });
        if (error) document.getElementById('payment-message').textContent = error.message;
    });
</script>
@endpush
@endsection
