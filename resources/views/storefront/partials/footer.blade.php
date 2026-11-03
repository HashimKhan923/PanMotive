<footer class="mt-24 border-t border-line bg-surface">
    <div class="container-x grid gap-10 py-14 md:grid-cols-2 lg:grid-cols-5">
        <div class="lg:col-span-2">
            <div class="flex items-center gap-2">
                <span class="grid h-8 w-8 place-items-center rounded-lg bg-accent text-accent-fg"><x-ui.icon name="wrench" class="w-4 h-4" /></span>
                <span class="text-base font-extrabold tracking-tight">Pan<span class="text-accent">Motive</span></span>
            </div>
            <p class="mt-3 max-w-sm text-sm text-muted">
                The US marketplace for automotive parts — genuine and aftermarket, matched to your exact vehicle,
                with buyer protection and cashback on every order.
            </p>
            <div class="mt-4 flex flex-wrap gap-2 text-xs text-muted">
                <span class="pill"><x-ui.icon name="shield" class="w-3.5 h-3.5" /> Buyer protection</span>
                <span class="pill"><x-ui.icon name="truck" class="w-3.5 h-3.5" /> Fast shipping</span>
            </div>
        </div>

        @foreach ([
            'Shop' => [['All Parts', route('products.index')], ["Today's Deals", route('deals.index')], ['Brands', route('products.index')]],
            'Account' => [['Your Orders', route('orders.index')], ['Wallet & Credits', route('wallet.index')], ['Sign in', route('login')]],
            'Sell' => [['Become a Seller', route('seller.onboarding.start')], ['Seller Portal', route('seller.dashboard')]],
            ...(($footerPages = cache()->remember('footer.pages', 300, fn () => \App\Models\Page::where('is_published', true)->where('show_in_footer', true)->orderBy('title')->get(['title', 'slug'])))->isNotEmpty()
                ? ['Help & policies' => $footerPages->map(fn ($p) => [$p->title, route('pages.show', $p)])->all()] : []),
        ] as $heading => $links)
            <div>
                <h4 class="text-sm font-semibold text-fg">{{ $heading }}</h4>
                <ul class="mt-3 space-y-2 text-sm text-muted">
                    @foreach ($links as [$label, $url])
                        <li><a href="{{ $url }}" class="hover:text-accent">{{ $label }}</a></li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>

    <div class="border-t border-line">
        <div class="container-x flex flex-col items-center justify-between gap-2 py-5 text-xs text-muted sm:flex-row">
            <span>&copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.</span>
            <span>@if ($supportEmail = config('marketplace.platform.support_email'))<a href="mailto:{{ $supportEmail }}" class="hover:text-accent">{{ $supportEmail }}</a> · @endif Secure checkout by Stripe · PCI-compliant</span>
        </div>
    </div>
</footer>
