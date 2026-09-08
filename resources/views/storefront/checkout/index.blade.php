<x-storefront-layout>
    <x-slot name="title">Checkout</x-slot>

    <div class="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:px-8">
        <h1 class="text-2xl font-semibold">Checkout</h1>

        @if (session('status'))
            <p class="mt-4 rounded-md bg-amber-50 px-4 py-3 text-sm text-amber-800">
                {{ session('status') }}
            </p>
        @endif

        <div class="mt-8 rounded-lg border border-dashed border-slate-300 p-8 text-center text-slate-500">
            Checkout flow (address, Stripe payment, per-seller order split) lands in Phase 3.
        </div>

        <form method="POST" action="{{ route('checkout.store') }}" class="mt-6">
            @csrf
            <button type="submit"
                    class="rounded-md bg-slate-900 px-5 py-2.5 text-white hover:bg-slate-700">
                Place order
            </button>
        </form>
    </div>
</x-storefront-layout>
