<div class="container-x py-10" data-reveal>
    <h1 class="text-2xl font-bold sm:text-3xl">Your cart</h1>

    @if ($groups->isEmpty())
        <div class="mt-8 card grid place-items-center p-16 text-center">
            <x-ui.icon name="cart" class="w-12 h-12 text-muted" />
            <p class="mt-4 text-lg font-semibold">Your cart is empty</p>
            <p class="mt-1 text-sm text-muted">Find the right part for your vehicle and it'll show up here.</p>
            <a href="{{ route('products.index') }}" class="btn-accent mt-6">Browse parts</a>
        </div>
    @else
        <div class="mt-8 grid gap-8 lg:grid-cols-[1fr_360px]">
            <div class="space-y-6">
                @foreach ($groups as $group)
                    <div class="card p-4" wire:key="grp-{{ $group['seller']?->id ?? $loop->index }}">
                        <p class="mb-3 flex items-center gap-2 border-b border-line pb-3 text-sm">
                            <x-ui.icon name="box" class="w-4 h-4 text-muted" />
                            Sold by <span class="font-semibold">{{ $group['seller']?->sellerProfile?->business_name ?? 'Verified seller' }}</span>
                        </p>
                        @if ($group['seller']?->sellerProfile?->salesPaused())
                            {{-- Same rule checkout enforces — better to say so here than fail at the last step. --}}
                            <p class="mb-3 flex items-start gap-2 rounded-xl bg-amber-500/10 px-3 py-2 text-xs text-amber-700 dark:text-amber-400">
                                <x-ui.icon name="clock" class="mt-0.5 w-3.5 h-3.5 shrink-0" />
                                This seller is away{{ $group['seller']->sellerProfile->vacation_until ? ' until '.$group['seller']->sellerProfile->vacation_until->format('M j') : '' }} and not taking orders — remove these items to check out, or save them for later.
                            </p>
                        @endif
                        <ul class="space-y-4">
                            @foreach ($group['lines'] as $line)
                                <li class="flex gap-4" wire:key="cl-{{ $line['product_id'] }}">
                                    <a href="{{ route('products.show', $line['slug']) }}" class="h-24 w-24 shrink-0 overflow-hidden rounded-xl bg-surface-2">
                                        <img src="{{ $line['image'] ?? '' }}" alt="{{ $line['title'] }}" class="h-full w-full object-cover">
                                    </a>
                                    @php($v = $line['variant_id'] ? ', ' . $line['variant_id'] : '')
                                    <div class="flex flex-1 flex-col">
                                        <a href="{{ route('products.show', $line['slug']) }}" class="line-clamp-2 text-sm font-semibold hover:text-accent">{{ $line['title'] }}</a>
                                        @if ($line['variant_label'])
                                            <span class="mt-0.5 text-xs text-muted">Option: {{ $line['variant_label'] }}</span>
                                        @endif
                                        <span class="mt-0.5 text-xs text-muted">
                                            ${{ number_format($line['unit_price'], 2) }} each
                                            @if ($line['unit_price'] < $line['list_unit_price'])
                                                <span class="text-emerald-600 line-through decoration-1">${{ number_format($line['list_unit_price'], 2) }}</span>
                                                <span class="rounded bg-emerald-500/10 px-1 text-[10px] font-semibold text-emerald-600">volume price</span>
                                            @endif
                                        </span>
                                        <div class="mt-auto flex flex-wrap items-center justify-between gap-2 pt-2">
                                            <div class="inline-flex items-center rounded-lg border border-line">
                                                <button wire:click="updateQuantity({{ $line['product_id'] }}, {{ $line['quantity'] - 1 }}{{ $v }})" class="px-3 py-1.5 text-muted hover:text-fg" aria-label="Decrease">−</button>
                                                <span class="w-8 text-center text-sm font-semibold">{{ $line['quantity'] }}</span>
                                                <button wire:click="updateQuantity({{ $line['product_id'] }}, {{ $line['quantity'] + 1 }}{{ $v }})" @disabled($line['quantity'] >= $line['max']) class="px-3 py-1.5 text-muted hover:text-fg disabled:opacity-30" aria-label="Increase">+</button>
                                            </div>
                                            <div class="flex items-center gap-4">
                                                <span class="text-sm font-bold">${{ number_format($line['line_total'], 2) }}</span>
                                                <button wire:click="remove({{ $line['product_id'] }}{{ $v }})" class="text-muted hover:text-rose-500" aria-label="Remove"><x-ui.icon name="trash" class="w-4 h-4" /></button>
                                            </div>
                                        </div>
                                        <button wire:click="moveToWishlist({{ $line['product_id'] }}{{ $v }})" class="mt-2 self-start text-xs font-medium text-muted hover:text-accent">
                                            <x-ui.icon name="heart" class="mr-1 inline w-3 h-3" /> Move to saved
                                        </button>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                        <p class="mt-3 border-t border-line pt-3 text-right text-xs text-muted">Seller subtotal: <span class="font-semibold text-fg">${{ number_format($group['subtotal'], 2) }}</span></p>
                    </div>
                @endforeach
            </div>

            <div class="card h-fit space-y-4 p-6">
                <h2 class="font-bold">Order summary</h2>
                <div class="flex justify-between text-sm"><span class="text-muted">Subtotal</span><span class="font-semibold">${{ number_format($subtotal, 2) }}</span></div>
                <div class="flex justify-between text-sm">
                    <span class="text-muted">Estimated shipping</span>
                    <span class="font-semibold">{{ $shippingFree ? 'FREE' : '$' . number_format($shipping, 2) }}</span>
                </div>
                @if (! $shippingFree && $freeThreshold > 0 && $subtotal < $freeThreshold)
                    <p class="rounded-lg bg-accent/10 px-3 py-2 text-xs text-accent">
                        Add ${{ number_format($freeThreshold - $subtotal, 2) }} more from a seller to unlock free shipping.
                    </p>
                @endif
                @foreach ($bundlesApplied as $b)
                    <div class="flex justify-between text-sm text-emerald-600"><span>Bundle: {{ $b['title'] }}</span><span>−${{ number_format($b['savings'], 2) }}</span></div>
                @endforeach
                <hr class="border-line">
                <div class="flex justify-between"><span class="font-semibold">Estimated total</span><span class="text-lg font-bold">${{ number_format($estimatedTotal, 2) }}</span></div>
                <a href="{{ route('checkout.index') }}" class="btn-accent w-full">Proceed to checkout <x-ui.icon name="arrow-right" class="w-4 h-4" /></a>
                <p class="text-center text-xs text-muted"><x-ui.icon name="shield" class="mr-1 inline w-3.5 h-3.5" /> Escrow-protected — sellers paid only on delivery</p>
            </div>
        </div>
    @endif
</div>
