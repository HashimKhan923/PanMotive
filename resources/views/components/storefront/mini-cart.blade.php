{{-- Slide-over that peeks in when an item is added to the cart. --}}
<div
    x-data="{
        open: false,
        loading: false,
        data: { count: 0, subtotal: 0, items: [] },
        timer: null,
        show() {
            this.open = true;
            this.refresh();
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.open = false, 5000);
        },
        refresh() {
            this.loading = true;
            fetch('{{ route('cart.summary') }}', { headers: { Accept: 'application/json' } })
                .then(r => r.json()).then(d => { this.data = d; this.loading = false; })
                .catch(() => this.loading = false);
        },
    }"
    x-init="
        window.addEventListener('mini-cart:show', () => show());
    "
    @keydown.escape.window="open = false"
    class="fixed inset-0 z-[60] pointer-events-none"
    x-cloak
>
    <div x-show="open" x-transition.opacity class="absolute inset-0 bg-black/30 pointer-events-auto" @click="open = false"></div>

    <aside
        x-show="open"
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="translate-x-full"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="translate-x-full"
        class="pointer-events-auto absolute right-0 top-0 flex h-full w-[360px] max-w-[88vw] flex-col bg-surface shadow-card-hover"
        @mouseenter="clearTimeout(timer)"
        @mouseleave="timer = setTimeout(() => open = false, 2500)"
    >
        <div class="flex items-center justify-between border-b border-line px-5 py-4">
            <p class="font-bold">Added to cart <span class="text-muted" x-text="'· ' + data.count + ' item' + (data.count === 1 ? '' : 's')"></span></p>
            <button @click="open = false" class="btn-ghost !p-2" aria-label="Close"><x-ui.icon name="x" class="w-6 h-6" /></button>
        </div>

        <div class="flex-1 space-y-3 overflow-y-auto p-5">
            <template x-for="item in data.items" :key="item.url + (item.variant || '')">
                <a :href="item.url" class="flex gap-3">
                    <img :src="item.image" alt="" class="h-14 w-14 shrink-0 rounded-lg object-cover bg-surface-2">
                    <div class="min-w-0 flex-1">
                        <p class="line-clamp-2 text-sm font-medium" x-text="item.title"></p>
                        <p class="text-xs text-muted">
                            <span x-show="item.variant" x-text="item.variant + ' · '"></span>
                            <span x-text="'Qty ' + item.qty"></span>
                        </p>
                    </div>
                    <span class="text-sm font-semibold" x-text="'$' + Number(item.line_total).toFixed(2)"></span>
                </a>
            </template>
            <p x-show="loading" class="text-sm text-muted">Updating…</p>
        </div>

        <div class="border-t border-line p-5">
            <div class="flex justify-between text-sm">
                <span class="text-muted">Subtotal</span>
                <span class="font-bold" x-text="'$' + Number(data.subtotal).toFixed(2)"></span>
            </div>
            <a href="{{ route('checkout.index') }}" class="btn-accent mt-3 w-full">Checkout</a>
            <a href="{{ route('cart.index') }}" class="btn-ghost mt-2 w-full">View cart</a>
        </div>
    </aside>
</div>
