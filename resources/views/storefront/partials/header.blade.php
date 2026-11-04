@php($cartCount = app(\App\Services\Cart\CartService::class)->itemCount())

{{-- Smart header: slides away while you scroll down to read, comes back the moment you scroll up,
     and gains a shadow once the page has moved. Never hides while the mobile menu is open. --}}
<header x-data="{ open: false, hidden: false, scrolled: false, lastY: 0 }"
        x-init="lastY = window.scrollY"
        @pm:show-header.window="hidden = false"
        @scroll.window.throttle.50ms="const y = window.scrollY;
            scrolled = y > 8;
            if (! open) hidden = y > 160 && y > lastY + 4 ? true : (y < lastY - 4 ? false : hidden);
            lastY = y"
        :class="{ '-translate-y-full': hidden, 'shadow-[0_10px_30px_-18px_rgb(23_24_29/.35)]': scrolled }"
        class="site-header sticky top-0 z-40 border-b border-line bg-surface/95 backdrop-blur-xl backdrop-saturate-150 transition-[transform,box-shadow] duration-300 ease-out">
    {{-- top strip --}}
    <div class="hidden border-b border-line bg-brand text-brand-fg lg:block">
        <div class="container-x flex h-8 items-center justify-between text-[11px] tracking-wide">
            <div class="flex items-center gap-5">
                <span class="inline-flex items-center gap-1.5"><x-ui.icon name="shield" class="w-3.5 h-3.5" /> Buyer protection on every order</span>
                <span class="inline-flex items-center gap-1.5"><x-ui.icon name="truck" class="w-3.5 h-3.5" /> Fast US shipping</span>
            </div>
            <div class="flex items-center gap-5">
                <span class="inline-flex items-center gap-1.5"><x-ui.icon name="sparkles" class="w-3.5 h-3.5 text-accent" /> Cashback credited on delivery</span>
                <a href="{{ route('seller.onboarding.start') }}" class="hover:text-accent">Sell on PanMotive</a>
            </div>
        </div>
    </div>

    <div class="container-x flex h-14 items-center gap-4 lg:h-16">
        {{-- logo --}}
        <a href="{{ route('home') }}" class="flex shrink-0 items-center text-fg" aria-label="PanMotive home">
            <x-ui.logo class="hidden h-7 w-auto min-[360px]:block sm:h-8 lg:h-9" />
            <x-ui.logo variant="mark" class="h-7 w-auto min-[360px]:hidden" />
        </a>

        {{-- search --}}
        <form action="{{ route('products.index') }}" method="GET" class="hidden flex-1 md:block">
            <div class="relative mx-auto max-w-2xl">
                <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 w-4 h-4 -translate-y-1/2 text-muted" />
                <input name="q" value="{{ request('q') }}" placeholder="Search parts, brands, or part numbers…"
                       class="input h-10 rounded-full bg-surface-2/60 pl-9 pr-4 text-sm" aria-label="Search products">
            </div>
        </form>

        {{-- actions --}}
        <nav class="ml-auto flex items-center gap-1">
            <button type="button" @click="$store.theme.toggle()" class="btn-ghost !p-2 !rounded-lg" aria-label="Toggle dark mode">
                <x-ui.icon name="sun" class="w-[18px] h-[18px] dark:hidden" />
                <x-ui.icon name="moon" class="hidden w-[18px] h-[18px] dark:block" />
            </button>

            @auth
                <a href="{{ route('wallet.index') }}" class="btn-ghost !p-2 !rounded-lg" aria-label="Wallet">
                    <x-ui.icon name="sparkles" class="w-[18px] h-[18px]" />
                </a>
                <a href="{{ route('orders.index') }}" class="hidden btn-ghost btn-sm sm:inline-flex">Orders</a>
                <div class="relative" x-data="{ acct: false }" @click.outside="acct = false">
                    <button type="button" @click="acct = !acct" class="btn-ghost !p-2 !rounded-lg" aria-label="Account menu" :aria-expanded="acct">
                        <x-ui.icon name="user" class="w-[18px] h-[18px]" />
                    </button>
                    <div x-show="acct" x-cloak x-transition.origin.top.right
                         class="absolute right-0 z-50 mt-2 w-52 overflow-hidden rounded-xl border border-line bg-surface py-1 shadow-card-hover">
                        <div class="border-b border-line px-4 py-2.5">
                            <p class="text-xs text-muted">Signed in as</p>
                            <p class="truncate text-sm font-semibold">{{ auth()->user()->name }}</p>
                        </div>
                        <a href="{{ route('dashboard') }}" class="block px-4 py-2 text-sm hover:bg-surface-2">Account</a>
                        <a href="{{ route('orders.index') }}" class="block px-4 py-2 text-sm hover:bg-surface-2">Orders</a>
                        @php($pmUnreadMsgs = auth()->user()->unreadBuyerMessageCount())
                        <a href="{{ route('messages.index') }}" class="flex items-center justify-between px-4 py-2 text-sm hover:bg-surface-2">
                            Messages
                            @if ($pmUnreadMsgs > 0)
                                <span class="rounded-full bg-accent px-1.5 text-[10px] font-bold text-accent-fg">{{ $pmUnreadMsgs }}</span>
                            @endif
                        </a>
                        <a href="{{ route('wishlist.index') }}" class="block px-4 py-2 text-sm hover:bg-surface-2">Saved items</a>
                        <a href="{{ route('wallet.index') }}" class="block px-4 py-2 text-sm hover:bg-surface-2">Wallet</a>
                        <a href="{{ route('profile') }}" class="block px-4 py-2 text-sm hover:bg-surface-2">Profile</a>
                        <a href="{{ route('marketing.preferences') }}" class="block px-4 py-2 text-sm hover:bg-surface-2">Marketing preferences</a>
                        <form method="POST" action="{{ route('logout') }}" class="border-t border-line">
                            @csrf
                            <button type="submit" class="block w-full px-4 py-2 text-left text-sm text-rose-500 hover:bg-surface-2">Log out</button>
                        </form>
                    </div>
                </div>
            @else
                <a href="{{ route('login') }}" class="hidden btn-ghost btn-sm sm:inline-flex">Log in</a>
                <a href="{{ route('register') }}" class="hidden btn-outline btn-sm sm:inline-flex">Register</a>
            @endauth

            <a href="{{ route('compare.index') }}" class="relative hidden btn-ghost !p-2 !rounded-lg sm:inline-flex" x-data
               :aria-label="`Compare, ${$store.compare.count} items`">
                <x-ui.icon name="grid" class="w-[18px] h-[18px]" />
                <span x-show="$store.compare.count > 0" x-cloak x-text="$store.compare.count"
                      class="absolute -right-0.5 -top-0.5 grid h-4 min-w-4 place-items-center rounded-full bg-accent px-1 text-[10px] font-bold text-accent-fg"></span>
            </a>

            <a href="{{ route('wishlist.index') }}" class="relative btn-ghost !p-2 !rounded-lg" x-data
               :aria-label="`Saved, ${$store.wishlist.count} items`">
                <x-ui.icon name="heart" class="w-[18px] h-[18px]" />
                <span x-show="$store.wishlist.count > 0" x-cloak x-text="$store.wishlist.count"
                      class="absolute -right-0.5 -top-0.5 grid h-4 min-w-4 place-items-center rounded-full bg-accent px-1 text-[10px] font-bold text-accent-fg"></span>
            </a>

            <a href="{{ route('cart.index') }}" id="cart-icon" class="relative btn-ghost !p-2 !rounded-lg"
               x-data="{}" :aria-label="`Cart, ${$store.cart.count} items`">
                <x-ui.icon name="cart" class="w-[18px] h-[18px]" />
                <span x-show="$store.cart.count > 0" x-cloak x-text="$store.cart.count"
                      class="absolute -right-0.5 -top-0.5 grid h-4 min-w-4 place-items-center rounded-full bg-accent px-1 text-[10px] font-bold text-accent-fg">{{ $cartCount ?: '' }}</span>
            </a>

            <button type="button" @click="open = true" class="btn-ghost !p-2 !rounded-lg lg:hidden" aria-label="Menu"><x-ui.icon name="menu" class="w-[18px] h-[18px]" /></button>
        </nav>
    </div>

    {{-- category nav --}}
    <div class="hidden border-t border-line lg:block">
        <div class="container-x flex h-10 items-center gap-5 text-[13px] font-medium">
            <a href="{{ route('products.index') }}" class="text-fg hover:text-accent">All Parts</a>
            <a href="{{ route('find-parts') }}" class="inline-flex items-center gap-1 text-fg hover:text-accent">
                <x-ui.icon name="search" class="w-3.5 h-3.5" /> Find parts
            </a>
            @foreach (\App\Models\Category::shopRoots()->take(7) as $cat)
                <a href="{{ route('products.index', ['category' => $cat->slug]) }}" class="text-muted hover:text-accent">{{ $cat->name }}</a>
            @endforeach
            <a href="{{ route('plus.show') }}" class="ml-auto font-semibold text-fg hover:text-accent">Plus</a>
            <a href="{{ route('deals.index') }}" class="font-semibold text-accent">Today's Deals</a>
        </div>
    </div>

    {{-- mobile drawer — teleported to <body> so the header's glass blur can't clip it --}}
    <template x-teleport="body">
    <div x-show="open" x-cloak @keydown.escape.window="open = false" class="fixed inset-0 z-50 lg:hidden"
         x-transition:enter="transition duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0">
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="open = false"></div>
        <div x-show="open" class="absolute right-0 top-0 h-full w-80 max-w-[85vw] overflow-y-auto bg-surface p-5 shadow-2xl"
             x-transition:enter="transition duration-500 ease-[cubic-bezier(.22,1,.36,1)]"
             x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
             x-transition:leave="transition duration-200 ease-in"
             x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full">
            <div class="flex items-center justify-between">
                <span class="font-bold">Menu</span>
                <button @click="open = false" class="btn-ghost !p-2"><x-ui.icon name="x" class="w-5 h-5" /></button>
            </div>
            <form action="{{ route('products.index') }}" method="GET" class="mt-4">
                <input name="q" placeholder="Search parts…" class="input h-11">
            </form>
            <nav class="mt-4 flex flex-col text-sm">
                <a href="{{ route('products.index') }}" class="py-2.5 font-semibold">All Parts</a>
                <a href="{{ route('find-parts') }}" class="py-2.5 font-semibold">Find parts for my vehicle</a>
                <a href="{{ route('plus.show') }}" class="py-2.5 font-semibold">PanMotive Plus</a>
                <a href="{{ route('deals.index') }}" class="py-2.5 font-semibold text-accent">Today's Deals</a>
                @foreach (\App\Models\Category::shopRoots() as $cat)
                    <a href="{{ route('products.index', ['category' => $cat->slug]) }}" class="py-2.5 text-muted">{{ $cat->name }}</a>
                @endforeach
                <hr class="my-3 border-line">
                <a href="{{ route('wishlist.index') }}" class="py-2.5">Saved items</a>
                <a href="{{ route('compare.index') }}" class="py-2.5">Compare</a>
                <a href="{{ route('track.form') }}" class="py-2.5">Track an order</a>
                <hr class="my-3 border-line">
                @auth
                    <a href="{{ route('orders.index') }}" class="py-2.5">Orders</a>
                    <a href="{{ route('messages.index') }}" class="py-2.5">Messages</a>
                    <a href="{{ route('wallet.index') }}" class="py-2.5">Wallet</a>
                    <a href="{{ route('dashboard') }}" class="py-2.5">Account</a>
                    <a href="{{ route('profile') }}" class="py-2.5">Profile</a>
                    <form method="POST" action="{{ route('logout') }}" class="pt-1">
                        @csrf
                        <button type="submit" class="py-2.5 text-left text-rose-500">Log out</button>
                    </form>
                @else
                    <a href="{{ route('login') }}" class="py-2.5">Log in</a>
                    <a href="{{ route('register') }}" class="py-2.5">Register</a>
                @endauth
            </nav>
        </div>
    </div>
    </template>
</header>
