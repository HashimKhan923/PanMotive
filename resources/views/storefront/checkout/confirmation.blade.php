<x-storefront-layout>
    <x-slot name="title">Order {{ $order->order_number }} — {{ config('app.name') }}</x-slot>

    @push('scripts')
        <script @nonce>window.addEventListener('load', () => window.pmCelebrate && window.pmCelebrate());</script>
    @endpush

    <div class="container-x max-w-2xl py-14" data-reveal>
        @if (session('success'))
            <p class="mb-4 rounded-xl bg-emerald-500/10 px-4 py-3 text-sm text-emerald-600">{{ session('success') }}</p>
        @endif

        <div class="card p-6 text-center sm:p-10">
            {{-- Circle draws, then the tick, then a ripple — and a confetti burst once. --}}
            <span class="relative mx-auto grid h-20 w-20 place-items-center text-emerald-500" x-data x-init="setTimeout(() => window.pmCelebrate && window.pmCelebrate(), 650)">
                <span class="absolute inset-0 animate-ping rounded-full bg-emerald-500/20 [animation-iteration-count:2]"></span>
                <svg class="pm-check-lg relative h-20 w-20" viewBox="0 0 56 56" fill="none" aria-hidden="true">
                    <circle cx="28" cy="28" r="25" stroke="currentColor" stroke-width="3"/>
                    <path d="M17 29l7.5 7.5L40 21" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </span>
            <h1 class="mt-5 text-2xl font-bold sm:text-3xl" data-split data-split-delay="0.5">Thanks — your order is in</h1>
            <p class="mt-1 text-sm text-muted">Order <span class="font-semibold text-fg">{{ $order->order_number }}</span> · {{ $order->status->label() }}</p>

            <table class="mt-8 w-full text-left text-sm">
                <thead class="border-b border-line text-xs uppercase text-muted">
                    <tr><th class="py-2">Item</th><th class="py-2 text-right">Qty</th><th class="py-2 text-right">Total</th></tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($order->items as $item)
                        <tr><td class="py-2.5">{{ $item->product_title }}</td><td class="py-2.5 text-right">{{ $item->quantity }}</td><td class="py-2.5 text-right">${{ number_format($item->line_total, 2) }}</td></tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="font-bold"><td colspan="2" class="pt-3 text-right">Total</td><td class="pt-3 text-right">${{ number_format($order->total, 2) }}</td></tr>
                </tfoot>
            </table>

            <div class="mt-8 flex flex-wrap justify-center gap-3">
                <a href="{{ route('orders.show', $order) }}" class="btn-brand">Track this order</a>
                <a href="{{ route('products.index') }}" class="btn-outline">Keep shopping</a>
            </div>
        </div>
    </div>
</x-storefront-layout>
