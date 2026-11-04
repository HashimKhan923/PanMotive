<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-bold">Hi {{ Str::of(auth()->user()->name)->before(' ') }} 👋</h1>
        <p class="mt-1 text-sm text-muted">Your PanMotive account at a glance.</p>
    </x-slot>

    @php
        $user = auth()->user();
        $orders = \App\Models\Order::where('buyer_id', $user->id)->latest()->take(5)->get();
        $openCount = \App\Models\Order::where('buyer_id', $user->id)
            ->whereNotIn('status', ['payout_released', 'refunded', 'cancelled'])->count();
        $wishlistCount = app(\App\Services\Cart\WishlistService::class)->count();
        $loyaltyAccounts = \App\Models\StoreLoyaltyAccount::where('user_id', $user->id)
            ->where('points', '>', 0)
            ->with('seller.sellerProfile')
            ->orderByDesc('points')
            ->take(5)->get();
        $affiliateCount = \App\Models\Affiliate::where('user_id', $user->id)->where('status', 'approved')->count();
    @endphp

    <div class="container-x py-10">
        {{-- quick stats --}}
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" data-reveal-group>
            <a href="{{ route('orders.index') }}" class="card p-5 transition hover:shadow-card-hover">
                <x-ui.icon name="box" class="w-6 h-6 text-accent" />
                <p class="mt-3 text-2xl font-bold">{{ $openCount }}</p>
                <p class="text-sm text-muted">Orders in progress</p>
            </a>
            <a href="{{ route('wallet.index') }}" class="card p-5 transition hover:shadow-card-hover">
                <x-ui.icon name="sparkles" class="w-6 h-6 text-accent" />
                <p class="mt-3 text-2xl font-bold">${{ number_format($user->wallet_balance, 2) }}</p>
                <p class="text-sm text-muted">Store credit</p>
            </a>
            <a href="{{ route('wishlist.index') }}" class="card p-5 transition hover:shadow-card-hover">
                <x-ui.icon name="heart" class="w-6 h-6 text-accent" />
                <p class="mt-3 text-2xl font-bold">{{ $wishlistCount }}</p>
                <p class="text-sm text-muted">Saved parts</p>
            </a>
            <a href="{{ route('plus.show') }}" class="card p-5 transition hover:shadow-card-hover">
                <x-ui.icon name="shield" class="w-6 h-6 text-accent" />
                <p class="mt-3 text-2xl font-bold capitalize">{{ $user->loyalty_tier ?? 'Bronze' }}</p>
                <p class="text-sm text-muted">
                    Loyalty tier
                    @if ($user->isPlusMember())<span class="pill ml-1 !bg-accent/10 !text-accent">Plus</span>@endif
                </p>
            </a>
        </div>

        <div class="mt-8 grid gap-6 lg:grid-cols-[1fr_320px]">
            {{-- recent orders --}}
            <div class="card p-6" data-reveal>
                <div class="flex items-center justify-between">
                    <h2 class="font-bold">Recent orders</h2>
                    <a href="{{ route('orders.index') }}" class="text-sm font-medium text-accent hover:underline">View all</a>
                </div>
                @if ($orders->isEmpty())
                    <div class="mt-6 grid place-items-center py-10 text-center text-sm text-muted">
                        <x-ui.icon name="box" class="w-8 h-8" />
                        <p class="mt-2">No orders yet.</p>
                        <a href="{{ route('products.index') }}" class="btn-accent btn-sm mt-4">Start shopping</a>
                    </div>
                @else
                    <ul class="mt-4 divide-y divide-line">
                        @foreach ($orders as $order)
                            <li class="flex items-center justify-between gap-3 py-3">
                                <div class="min-w-0">
                                    <a href="{{ route('orders.show', $order) }}" class="font-medium hover:text-accent">{{ $order->order_number }}</a>
                                    <p class="text-xs text-muted">{{ optional($order->placed_at ?? $order->created_at)->format('M j, Y') }} · {{ $order->items->count() }} {{ Str::plural('item', $order->items->count()) }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-semibold">${{ number_format($order->total, 2) }}</p>
                                    <span class="pill">{{ $order->status->label() }}</span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- account shortcuts --}}
            <div class="space-y-4" data-reveal>
                <div class="card p-6">
                    <h2 class="font-bold">Account</h2>
                    <nav class="mt-3 flex flex-col text-sm">
                        <a href="{{ route('profile') }}" class="flex items-center justify-between py-2.5 hover:text-accent">Profile &amp; password <x-ui.icon name="chevron-right" class="w-4 h-4" /></a>
                        <a href="{{ route('messages.index') }}" class="flex items-center justify-between py-2.5 hover:text-accent">Messages <x-ui.icon name="chevron-right" class="w-4 h-4" /></a>
                        <a href="{{ route('marketing.preferences') }}" class="flex items-center justify-between py-2.5 hover:text-accent">Marketing preferences <x-ui.icon name="chevron-right" class="w-4 h-4" /></a>
                        <a href="{{ route('wallet.index') }}" class="flex items-center justify-between py-2.5 hover:text-accent">Wallet &amp; referrals <x-ui.icon name="chevron-right" class="w-4 h-4" /></a>
                        <a href="{{ route('subscriptions.index') }}" class="flex items-center justify-between py-2.5 hover:text-accent">Subscribe &amp; Save <x-ui.icon name="chevron-right" class="w-4 h-4" /></a>
                        <a href="{{ route('plus.show') }}" class="flex items-center justify-between py-2.5 hover:text-accent">PanMotive Plus <x-ui.icon name="chevron-right" class="w-4 h-4" /></a>
                        <a href="{{ route('track.form') }}" class="flex items-center justify-between py-2.5 hover:text-accent">Track an order <x-ui.icon name="chevron-right" class="w-4 h-4" /></a>
                    </nav>
                </div>
                @if ($loyaltyAccounts->isNotEmpty() || $affiliateCount > 0)
                    <div class="card p-6">
                        <div class="flex items-center justify-between">
                            <h2 class="font-bold">Rewards</h2>
                            @if ($affiliateCount > 0)
                                <a href="{{ route('affiliate.dashboard') }}" class="text-sm font-medium text-accent hover:underline">Affiliate</a>
                            @endif
                        </div>
                        @if ($loyaltyAccounts->isNotEmpty())
                            <ul class="mt-3 divide-y divide-line text-sm">
                                @foreach ($loyaltyAccounts as $acct)
                                    <li class="flex items-center justify-between py-2.5">
                                        <a href="{{ route('sellers.show', $acct->seller->sellerProfile) }}" class="min-w-0 truncate hover:text-accent">
                                            {{ $acct->seller->sellerProfile?->business_name ?? 'Store' }}
                                        </a>
                                        <span class="shrink-0 font-semibold">{{ number_format($acct->points) }} pts</span>
                                    </li>
                                @endforeach
                            </ul>
                            <p class="mt-3 text-xs text-muted">Redeem points on each store's page for PanMotive credit.</p>
                        @else
                            <p class="mt-2 text-sm text-muted">You're an approved affiliate. Share your links from the affiliate dashboard.</p>
                        @endif
                    </div>
                @endif

                <div class="card bg-brand p-6 text-brand-fg">
                    <h3 class="font-bold">Sell on PanMotive</h3>
                    <p class="mt-1 text-sm text-white/70">Turn your inventory into sales with escrow-backed payouts.</p>
                    <a href="{{ route('seller.onboarding.start') }}" class="btn-accent btn-sm mt-4">Get started</a>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
