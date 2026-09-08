<div class="mx-auto max-w-3xl px-4 py-12 sm:px-6 lg:px-8">
    <h1 class="text-2xl font-semibold">Your cart</h1>

    @if (empty($items))
        <div class="mt-8 rounded-lg border border-dashed border-slate-300 p-10 text-center text-slate-500">
            Your cart is empty.
            <a href="{{ route('products.index') }}" class="mt-2 block text-slate-900 underline">Browse the shop</a>
        </div>
    @else
        <ul class="mt-8 divide-y divide-slate-100">
            @foreach ($items as $item)
                <li class="flex items-center justify-between py-3 text-sm">
                    <span>{{ $item['title'] }} &times; {{ $item['quantity'] }}</span>
                    <span>${{ number_format($item['unit_price'] * $item['quantity'], 2) }}</span>
                </li>
            @endforeach
        </ul>

        <div class="mt-6 flex items-center justify-between border-t border-slate-200 pt-4">
            <span class="font-medium">Subtotal</span>
            <span class="text-lg font-semibold">${{ number_format($subtotal, 2) }}</span>
        </div>

        <a href="{{ route('checkout.index') }}"
           class="mt-6 block rounded-md bg-slate-900 px-5 py-3 text-center text-white hover:bg-slate-700">
            Proceed to checkout
        </a>
    @endif
</div>
