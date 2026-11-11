<footer class="pm-charcoal mt-24 border-t border-line bg-brand text-fg">
    <div class="container-x grid gap-10 py-14 md:grid-cols-2 lg:grid-cols-5">
        <div class="lg:col-span-2">
            <div class="flex items-center gap-2">
                <x-ui.logo class="h-[25px] w-auto" />
            </div>
            <p class="mt-3 max-w-sm text-sm text-muted">
                Panmotive is an automotive marketplace for parts and accessories. Items are sold by independent sellers.
            </p>
            <div class="mt-4 flex flex-wrap gap-2 text-xs text-muted">
                <span class="pill"><x-ui.icon name="shield" class="w-4 h-4" /> Payment held until delivery</span>
                <span class="pill"><x-ui.icon name="truck" class="w-4 h-4" /> Independent sellers</span>
            </div>
        </div>

        @foreach ([
            'Shop' => [['All parts', route('products.index')], ["Today's deals", route('deals.index')], ['Brands', route('products.index')]],
            'Account' => [['Your orders', route('orders.index')], ['Wallet and credits', route('wallet.index')], ['Log in', route('login')]],
            'Sell' => [['Become a seller', route('seller.onboarding.start')], ['Seller portal', route('seller.dashboard')]],
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
            <span>@if ($supportEmail = \App\Support\MailSettings::supportEmail())<a href="mailto:{{ $supportEmail }}" class="hover:text-accent">{{ $supportEmail }}</a> · @endif Secure checkout by Stripe</span>
        </div>
    </div>
</footer>
