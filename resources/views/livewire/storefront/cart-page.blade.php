<div class="container-x py-10" data-reveal>
    <h1 class="text-2xl font-bold sm:text-3xl">Your cart</h1>

    @if ($lines->isEmpty())
        <div class="mt-8 card grid place-items-center p-16 text-center">
            <x-ui.icon name="cart" class="w-12 h-12 text-muted" />
            <p class="mt-4 text-lg font-semibold">Your cart is empty</p>
            <p class="mt-1 text-sm text-muted">Find the right part for your vehicle and it'll show up here.</p>
            <a href="{{ route('products.index') }}" class="btn-accent mt-6">Browse parts</a>
        </div>
    @else
        <div class="mt-8 grid gap-8 lg:grid-cols-[1fr_360px]">
            <ul class="space-y-4">
                @foreach ($lines as $line)
                    <li class="card flex gap-4 p-4" wire:key="cl-{{ $line['product_id'] }}">
                        <div class="h-24 w-24 shrink-0 overflow-hidden rounded-xl bg-surface-2">
                            <img src="{{ $line['image'] ?? '' }}" alt="{{ $line['title'] }}" class="h-full w-full object-cover">
                        </div>
                        <div class="flex flex-1 flex-col">
                            <a href="{{ route('products.show', $line['slug']) }}" class="line-clamp-2 text-sm font-semibold hover:text-accent">{{ $line['title'] }}</a>
                            <span class="mt-0.5 text-xs text-muted">${{ number_format($line['unit_price'], 2) }} each</span>
                            <div class="mt-auto flex items-center justify-between pt-2">
                                <div class="inline-flex items-center rounded-lg border border-line">
                                    <button wire:click="updateQuantity({{ $line['product_id'] }}, {{ $line['quantity'] - 1 }})" class="px-3 py-1.5 text-muted hover:text-fg" aria-label="Decrease">−</button>
                                    <span class="w-8 text-center text-sm font-semibold">{{ $line['quantity'] }}</span>
                                    <button wire:click="updateQuantity({{ $line['product_id'] }}, {{ $line['quantity'] + 1 }})" class="px-3 py-1.5 text-muted hover:text-fg" aria-label="Increase">+</button>
                                </div>
                                <div class="flex items-center gap-4">
                                    <span class="text-sm font-bold">${{ number_format($line['line_total'], 2) }}</span>
                                    <button wire:click="remove({{ $line['product_id'] }})" class="text-muted hover:text-rose-500" aria-label="Remove"><x-ui.icon name="trash" class="w-4 h-4" /></button>
                                </div>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="card h-fit space-y-4 p-6">
                <h2 class="font-bold">Order summary</h2>
                <div class="flex justify-between text-sm"><span class="text-muted">Subtotal</span><span class="font-semibold">${{ number_format($subtotal, 2) }}</span></div>
                <div class="flex justify-between text-sm"><span class="text-muted">Shipping</span><span class="text-muted">Calculated at checkout</span></div>
                <hr class="border-line">
                <div class="flex justify-between"><span class="font-semibold">Estimated total</span><span class="text-lg font-bold">${{ number_format($subtotal, 2) }}</span></div>
                <a href="{{ route('checkout.index') }}" class="btn-accent w-full">Proceed to checkout <x-ui.icon name="arrow-right" class="w-4 h-4" /></a>
                <p class="text-center text-xs text-muted"><x-ui.icon name="shield" class="mr-1 inline w-3.5 h-3.5" /> Escrow-protected — sellers paid only on delivery</p>
            </div>
        </div>
    @endif
</div>
