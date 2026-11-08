@extends('layouts.storefront')

@section('title', config('app.name') . ' | Upgrade your drive.')

@section('content')
@php
    $homeBanners = \App\Models\Banner::forHome();
    $featured = \App\Models\Product::listed()->with('brand', 'seller.sellerProfile')->latest()->take(8)->get();
    $deals = \App\Models\Product::onDeal()->listed()
        ->with('brand', 'seller.sellerProfile')->orderByRaw('(price - sale_price) / price DESC')->take(4)->get();
    $categories = \App\Models\Category::shopRoots()->take(8);
    $brands = cache()->remember('home.brands.v2', 3600, fn () => \App\Models\Brand::whereHas('products', fn ($q) => $q->listed())
        ->withCount(['products' => fn ($q) => $q->listed()])->orderByDesc('products_count')->take(14)->pluck('name', 'slug'));

    $garage = app(\App\Services\GarageService::class)->current();
    $forVehicle = $garage
        ? \App\Models\Product::listed()
            ->whereHas('fitments', fn ($q) => $q->where('vehicles.id', $garage['id']))
            ->with('brand')->latest()->take(4)->get()
        : collect();

    $recent = app(\App\Services\RecentlyViewedService::class)->products(limit: 4);

    $adServer = app(\App\Services\Seller\AdServingService::class);
    $sponsored = $adServer->forPlacement('home', [], 4);
    $adServer->recordImpressions($sponsored);

    $fromFollowed = auth()->check()
        ? \App\Models\Product::listed()
            ->whereIn('seller_id', auth()->user()->followedSellers()->pluck('users.id'))
            ->with('brand')->latest()->take(4)->get()
        : collect();
@endphp

{{-- ================= HERO ================= --}}
<section class="pm-grain relative isolate overflow-hidden bg-surface text-fg">
    {{-- Drifting colour fields — pure CSS, paused for reduced-motion users. --}}
    <div class="pm-aurora -z-10" aria-hidden="true"><span></span><span></span><span></span></div>
    <div data-parallax="0.35" class="pointer-events-none absolute -right-24 -top-24 -z-10 h-96 w-96 rounded-full bg-accent/5 blur-3xl"></div>

    <div class="container-x relative grid gap-12 py-16 lg:grid-cols-2 lg:py-24">
        <div data-reveal="slide-up" class="flex flex-col justify-center">
            <span class="pill w-fit !bg-surface !text-muted ring-1 ring-line">
                <x-ui.icon name="sparkles" class="w-4 h-4 text-fg" /> {{ config('marketplace.platform.hero_badge') }}
            </span>
            <h1 class="mt-5 text-4xl font-bold leading-[1.05] sm:text-5xl lg:text-6xl" data-split>
                {{ config('marketplace.platform.hero_line_1') }}<br>{{ config('marketplace.platform.hero_line_2') }} <span class="pm-gradient-text">{{ config('marketplace.platform.hero_accent') }}</span>
            </h1>
            <p class="mt-5 max-w-md text-lg text-muted">
                {{ config('marketplace.platform.hero_text') }}
            </p>

            <div id="garage" class="mt-8 scroll-mt-24 rounded-2xl bg-surface/80 p-2 shadow-[0_24px_60px_-28px_rgb(23_24_29/.45)] ring-1 ring-line backdrop-blur-xl">
                @livewire('storefront.vehicle-selector')
            </div>

            <div class="mt-5 flex flex-wrap gap-3">
                <a href="{{ route('products.index') }}" data-magnetic class="btn-outline">Shop all parts <x-ui.icon name="arrow-right" class="w-4 h-4" /></a>
                <a href="{{ route('deals.index') }}" data-magnetic class="btn-outline !bg-surface/70 backdrop-blur">Today's deals</a>
            </div>

            <div class="mt-6 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-muted">
                <span class="inline-flex items-center gap-1.5"><x-ui.icon name="check" class="w-4 h-4 text-fg" /> {{ config('marketplace.platform.hero_point_1') }}</span>
                <span class="inline-flex items-center gap-1.5"><x-ui.icon name="check" class="w-4 h-4 text-fg" /> {{ config('marketplace.platform.hero_point_2') }}</span>
                <span class="inline-flex items-center gap-1.5"><x-ui.icon name="check" class="w-4 h-4 text-fg" /> {{ config('marketplace.platform.hero_point_3') }}</span>
            </div>
        </div>

        <div data-reveal="scale" class="relative hidden lg:block">
            <div class="grid h-full grid-cols-2 gap-4">
                @foreach ($featured->take(4) as $i => $p)
                    {{-- Three layers, three transforms: scroll parallax → idle float → pointer tilt. --}}
                    <div data-parallax="{{ $i % 2 ? '-0.12' : '0.12' }}" class="{{ $i % 2 ? 'mt-8' : '' }}">
                    <div class="pm-float" style="animation-delay: -{{ $i * 1.3 }}s">
                    <a href="{{ route('products.show', $p->slug) }}" data-morph data-tilt
                       class="pm-tilt group relative block overflow-hidden rounded-2xl bg-surface shadow-[0_24px_50px_-24px_rgb(23_24_29/.5)] ring-1 ring-line">
                        <div class="pm-glare"></div>
                        @if ($p->hasMedia('images'))
                            <img src="{{ $p->getFirstMediaUrl('images', 'thumb') ?: $p->getFirstMediaUrl('images') }}"
                                 alt="{{ $p->title }}" class="aspect-square w-full object-cover transition group-hover:scale-105">
                        @else
                            <div class="grid aspect-square w-full place-items-center bg-surface-2">
                                <x-ui.icon name="image" class="w-8 h-8 text-muted" />
                            </div>
                        @endif
                        <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent p-3">
                            <p class="line-clamp-1 text-xs font-semibold text-white">{{ $p->title }}</p>
                            <p class="text-xs font-bold text-white">${{ number_format($p->effectivePrice(), 2) }}</p>
                        </div>
                    </a>
                    </div>
                    </div>
                @endforeach
                @if ($featured->count() < 4)
                    <div class="col-span-2 grid place-items-center rounded-2xl bg-surface p-10 text-center text-sm text-muted ring-1 ring-line">
                        Seller listings appear here.
                    </div>
                @endif
            </div>
        </div>
    </div>
</section>

{{-- ================= BRANDS MARQUEE ================= --}}
@if ($brands->count() >= 4)
    <section class="border-b border-line bg-surface py-6" aria-label="Brands sold on Panmotive">
        <div class="pm-marquee text-lg font-bold uppercase tracking-widest text-muted">
            @foreach ([0, 1] as $copy)
                <div @if ($copy) aria-hidden="true" @endif>
                    @foreach ($brands as $slug => $b)
                        <a href="{{ route('products.index', ['brand' => $slug]) }}" class="whitespace-nowrap transition hover:text-accent">{{ $b }}</a>
                    @endforeach
                </div>
            @endforeach
        </div>
    </section>
@endif

{{-- ================= CATEGORIES (kept first, right under the hero) ================= --}}
<section class="container-x py-16" data-reveal>
    <div class="flex items-end justify-between">
        <div>
            <span class="pm-cut-bar" aria-hidden="true"></span>
            <h2 data-reveal="mask" class="text-xl font-bold tracking-tight sm:text-2xl">Shop by category</h2>
            <p class="mt-1 text-sm text-muted">Find parts and accessories by category.</p>
        </div>
        <a href="{{ route('products.index') }}" class="hidden text-sm font-semibold text-fg underline underline-offset-2 sm:inline-flex items-center gap-1">
            View all <x-ui.icon name="arrow-right" class="w-4 h-4" />
        </a>
    </div>

    <div class="mt-8 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4" data-reveal-group>
        @forelse ($categories as $cat)
            <a href="{{ route('products.index', ['category' => $cat->slug]) }}"
               data-tilt class="pm-tilt group card relative flex items-center gap-4 p-5 hover:shadow-card-hover hover:ring-1 hover:ring-accent/40">
                <div class="pm-glare"></div>
                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-surface-2 text-fg transition duration-500 group-hover:rotate-[20deg] group-hover:scale-110 group-hover:bg-accent group-hover:text-accent-fg">
                    <x-ui.icon :name="['brakes' => 'brakes', 'engine' => 'parts', 'suspension' => 'wheel', 'electrical' => 'batteries', 'cooling' => 'oil', 'lighting' => 'lighting', 'body-exterior' => 'vehicle'][$cat->slug] ?? 'package'" class="w-6 h-6" />
                </span>
                <div>
                    <p class="font-semibold text-fg group-hover:text-accent">{{ $cat->name }}</p>
                    <p class="text-xs text-muted">{{ number_format($cat->listings_count) }} {{ Str::plural('part', $cat->listings_count) }}</p>
                </div>
            </a>
        @empty
            <p class="col-span-full text-sm text-muted">Categories will appear once the catalog is populated.</p>
        @endforelse
    </div>
</section>

<x-home.banners :banners="$homeBanners['below_categories'] ?? []" />

{{-- ================= FOR YOUR VEHICLE ================= --}}
@if ($forVehicle->isNotEmpty())
    <section class="container-x py-14" data-reveal>
        <div class="flex items-end justify-between">
            <div>
                <h2 class="text-lg font-bold tracking-tight sm:text-xl">For your {{ $garage['label'] }}</h2>
                <p class="mt-1 text-sm text-muted">Parts sellers have listed for this vehicle.</p>
            </div>
            <a href="{{ route('products.index', ['vehicleId' => $garage['id']]) }}" class="hidden text-sm font-medium text-accent hover:underline sm:block">See all</a>
        </div>
        <div class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($forVehicle as $product)
                <x-product-card :product="$product" />
            @endforeach
        </div>
    </section>
@endif

{{-- ================= FROM STORES YOU FOLLOW ================= --}}
@if ($fromFollowed->isNotEmpty())
    <section class="container-x py-14" data-reveal>
        <h2 class="text-lg font-bold tracking-tight sm:text-xl">New from stores you follow</h2>
        <div class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($fromFollowed as $product)
                <x-product-card :product="$product" />
            @endforeach
        </div>
    </section>
@endif

{{-- ================= SPONSORED ================= --}}
@if ($sponsored->isNotEmpty())
    <section class="container-x py-14" data-reveal>
        <div class="flex items-center gap-2">
            <h2 class="text-lg font-bold tracking-tight sm:text-xl">Featured parts</h2>
            <span class="rounded-full bg-surface-2 px-2 py-0.5 text-[10px] font-semibold uppercase text-muted">Sponsored</span>
        </div>
        <div class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($sponsored as $ad)
                <x-product-card :product="$ad['product']"
                    :href="route('ad.click', ['source' => $ad['source'], 'ref' => $ad['ref_id'], 'p' => $ad['product']->id])" />
            @endforeach
        </div>
    </section>
@endif

{{-- ================= DEALS RAIL ================= --}}
@if ($deals->isNotEmpty())
<section class="bg-surface py-16">
    <div class="container-x" data-reveal>
        <div class="flex items-end justify-between">
            <h2 data-reveal="mask" class="text-xl font-bold tracking-tight sm:text-2xl">Today's deals</h2>
            <a href="{{ route('deals.index') }}" class="pm-tap relative text-sm font-semibold text-fg underline underline-offset-2">See all deals</a>
        </div>
        <div class="mt-8 grid grid-cols-2 gap-5 lg:grid-cols-4" data-reveal-group>
            @foreach ($deals as $product)
                <x-product-card :product="$product" />
            @endforeach
        </div>
    </div>
</section>
@endif

<x-home.banners :banners="$homeBanners['below_deals'] ?? []" />

{{-- ================= FEATURED ================= --}}
<section class="container-x py-16" data-reveal>
    <div class="flex items-end justify-between">
        <h2 data-reveal="mask" class="text-xl font-bold tracking-tight sm:text-2xl">New listings</h2>
        <a href="{{ route('products.index') }}" class="pm-tap relative text-sm font-semibold text-fg underline underline-offset-2">Browse all parts</a>
    </div>
    @if ($featured->isNotEmpty())
        <div class="mt-8 grid grid-cols-2 gap-5 sm:grid-cols-3 lg:grid-cols-4" data-reveal-group>
            @foreach ($featured as $product)
                <x-product-card :product="$product" />
            @endforeach
        </div>
    @else
        <div class="mt-8 card grid place-items-center p-16 text-center">
            <x-ui.icon name="box" class="w-12 h-12 text-muted" />
            <p class="mt-3 font-semibold">No active listings yet</p>
            <p class="mt-1 text-sm text-muted">Parts appear here when sellers publish them.</p>
        </div>
    @endif
</section>

<x-home.banners :banners="$homeBanners['below_listings'] ?? []" />

{{-- ================= RECENTLY VIEWED ================= --}}
@if ($recent->isNotEmpty())
    <section class="container-x py-14" data-reveal>
        <h2 class="text-lg font-bold tracking-tight sm:text-xl">Recently viewed</h2>
        <div class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($recent as $product)
                <x-product-card :product="$product" />
            @endforeach
        </div>
    </section>
@endif

{{-- ================= VALUE PROPS ================= --}}
<section class="border-y border-line bg-surface py-16">
    <div class="container-x grid gap-8 md:grid-cols-3" data-reveal-group>
        @foreach ([
            ['shield', 'Payment held until delivery', 'Sellers are paid after your order is delivered.'],
            ['sparkles', 'Cashback on delivered orders', 'Cashback goes to your wallet. Use it at checkout.'],
            ['truck', 'Listed vehicle compatibility', 'Sellers list the vehicles a part fits. Check the list before ordering.'],
        ] as [$icon, $title, $body])
            <div class="flex gap-4">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-surface-2 text-fg"><x-ui.icon :name="$icon" class="w-6 h-6" /></span>
                <div>
                    <h3 class="font-semibold">{{ $title }}</h3>
                    <p class="mt-1 text-sm text-muted">{{ $body }}</p>
                </div>
            </div>
        @endforeach
    </div>
</section>

{{-- ================= CTA ================= --}}
<section class="container-x py-16">
    <div class="pm-grain relative isolate overflow-hidden rounded-3xl bg-brand p-10 text-brand-fg sm:p-16" data-reveal="scale">
        <div class="pm-cut-field hidden md:block" aria-hidden="true"></div>
        <x-ui.logo variant="symbol" :on-red="true" class="absolute right-12 top-1/2 hidden h-20 w-auto -translate-y-1/2 md:block lg:right-20" aria-hidden="true" />
        <div class="pm-aurora -z-10 opacity-60" aria-hidden="true"><span></span><span></span><span></span></div>
        <h2 class="relative max-w-md text-2xl font-bold sm:text-3xl">Sell on Panmotive</h2>
        <p class="relative mt-3 max-w-md text-white/70">
            List your parts and reach buyers searching for them. 10% commission. Payouts are released after delivery.
        </p>
        <a href="{{ route('seller.onboarding.start') }}" data-magnetic class="btn-accent relative mt-6">Start selling <x-ui.icon name="arrow-right" class="w-4 h-4" /></a>
    </div>
</section>
@endsection
