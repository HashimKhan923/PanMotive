@php($cartCount = app(\App\Services\Cart\CartService::class)->itemCount())

<header x-data="{ open: false }" class="sticky top-0 z-40 border-b border-line bg-surface/90 backdrop-blur">
    {{-- top strip --}}
    <div class="hidden border-b border-line bg-brand text-brand-fg lg:block">
        <div class="container-x flex h-9 items-center justify-between text-xs">
            <div class="flex items-center gap-5">
                <span class="inline-flex items-center gap-1.5"><x-ui.icon name="shield" class="w-3.5 h-3.5" /> Buyer protection on every order</span>
                <span class="inline-flex items-center gap-1.5"><x-ui.icon name="truck" class="w-3.5 h-3.5" /> Fast US shipping</span>
            </div>
            <div class="flex items-center gap-5">
                <span class="inline-flex items-center gap-1.5"><x-ui.icon name="sparkles" class="w-3.5 h-3.5 text-accent" /> Cashback credited on delivery</span>
                <a href="{{ route('two-factor.setup') }}" class="hover:text-accent">Sell on Pan Motive</a>
            </div>
        </div>
    </div>

    <div class="container-x flex h-16 items-center gap-4 lg:h-20">
        {{-- logo --}}
        <a href="{{ route('home') }}" class="flex shrink-0 items-center gap-2">
            <span class="grid h-9 w-9 place-items-center rounded-xl bg-accent text-accent-fg">
                <x-ui.icon name="wrench" class="w-5 h-5" />
            </span>
            <span class="text-lg font-extrabold tracking-tight text-fg">Pan<span class="text-accent">Motive</span></span>
        </a>

        {{-- search --}}
        <form action="{{ route('products.index') }}" method="GET" class="hidden flex-1 md:block">
            <div class="relative mx-auto max-w-2xl">
                <x-ui.icon name="search" class="pointer-events-none absolute left-3.5 top-1/2 w-5 h-5 -translate-y-1/2 text-muted" />
                <input name="q" value="{{ request('q') }}" placeholder="Search parts, brands, or part numbers…"
                       class="input h-11 pl-11 pr-4" aria-label="Search products">
            </div>
        </form>

        {{-- actions --}}
        <nav class="ml-auto flex items-center gap-1">
            <button type="button" @click="$store.theme.toggle()" class="btn-ghost !p-2.5" aria-label="Toggle dark mode">
                <x-ui.icon name="sun" class="w-5 h-5 dark:hidden" />
                <x-ui.icon name="moon" class="hidden w-5 h-5 dark:block" />
            </button>

            @auth
                <a href="{{ route('wallet.index') }}" class="btn-ghost !p-2.5" aria-label="Wallet">
                    <x-ui.icon name="sparkles" class="w-5 h-5" />
                </a>
                <a href="{{ route('orders.index') }}" class="hidden btn-ghost sm:inline-flex">Orders</a>
                <a href="{{ route('dashboard') }}" class="btn-ghost !p-2.5" aria-label="Account"><x-ui.icon name="user" class="w-5 h-5" /></a>
            @else
                <a href="{{ route('login') }}" class="hidden btn-ghost sm:inline-flex">Log in</a>
                <a href="{{ route('register') }}" class="hidden btn-outline btn-sm sm:inline-flex">Register</a>
            @endauth

            <a href="{{ route('cart.index') }}" id="cart-icon" class="relative btn-ghost !p-2.5"
               x-data="{}" :aria-label="`Cart, ${$store.cart.count} items`">
                <x-ui.icon name="cart" class="w-5 h-5" />
                <span x-show="$store.cart.count > 0" x-cloak x-text="$store.cart.count"
                      class="absolute -right-0.5 -top-0.5 grid h-4 min-w-4 place-items-center rounded-full bg-accent px-1 text-[10px] font-bold text-accent-fg">{{ $cartCount ?: '' }}</span>
            </a>

            <button type="button" @click="open = true" class="btn-ghost !p-2.5 lg:hidden" aria-label="Menu"><x-ui.icon name="menu" class="w-5 h-5" /></button>
        </nav>
    </div>

    {{-- category nav --}}
    <div class="hidden border-t border-line lg:block">
        <div class="container-x flex h-11 items-center gap-6 text-sm font-medium">
            <a href="{{ route('products.index') }}" class="text-fg hover:text-accent">All Parts</a>
            @foreach (\App\Models\Category::whereNull('parent_id')->orderBy('name')->limit(7)->get() as $cat)
                <a href="{{ route('products.index', ['category' => $cat->slug]) }}" class="text-muted hover:text-accent">{{ $cat->name }}</a>
            @endforeach
            <a href="{{ route('products.index', ['sort' => 'price_asc']) }}" class="ml-auto font-semibold text-accent">Today's Deals</a>
        </div>
    </div>

    {{-- mobile drawer --}}
    <div x-show="open" x-cloak @keydown.escape.window="open = false" class="fixed inset-0 z-50 lg:hidden">
        <div class="absolute inset-0 bg-black/50" @click="open = false"></div>
        <div class="absolute right-0 top-0 h-full w-80 max-w-[85vw] bg-surface p-5" x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0">
            <div class="flex items-center justify-between">
                <span class="font-bold">Menu</span>
                <button @click="open = false" class="btn-ghost !p-2"><x-ui.icon name="x" class="w-5 h-5" /></button>
            </div>
            <form action="{{ route('products.index') }}" method="GET" class="mt-4">
                <input name="q" placeholder="Search parts…" class="input h-11">
            </form>
            <nav class="mt-4 flex flex-col text-sm">
                <a href="{{ route('products.index') }}" class="py-2.5 font-semibold">All Parts</a>
                @foreach (\App\Models\Category::whereNull('parent_id')->orderBy('name')->limit(8)->get() as $cat)
                    <a href="{{ route('products.index', ['category' => $cat->slug]) }}" class="py-2.5 text-muted">{{ $cat->name }}</a>
                @endforeach
                <hr class="my-3 border-line">
                @auth
                    <a href="{{ route('orders.index') }}" class="py-2.5">Orders</a>
                    <a href="{{ route('wallet.index') }}" class="py-2.5">Wallet</a>
                    <a href="{{ route('dashboard') }}" class="py-2.5">Account</a>
                @else
                    <a href="{{ route('login') }}" class="py-2.5">Log in</a>
                    <a href="{{ route('register') }}" class="py-2.5">Register</a>
                @endauth
            </nav>
        </div>
    </div>
</header>
