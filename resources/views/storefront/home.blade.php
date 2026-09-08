@extends('layouts.storefront')

@section('title', config('app.name') . ' — Auto Parts for Every Vehicle')

@section('content')
@php
    $stats = cache()->remember('home.stats.v2.' . now()->format('Y-m-d-H'), 1800, fn () => [
        'listings' => \App\Models\Product::where('status', 'active')->count(),
        'sellers'  => \App\Models\SellerProfile::where('status', 'approved')->count(),
        'makes'    => \App\Models\VehicleMake::count(),
        'orders'   => \App\Models\Order::whereNotIn('status', ['placed', 'cancelled'])->count(),
    ]);
    $featured = \App\Models\Product::where('status', 'active')->with('brand', 'seller.sellerProfile')->latest()->take(8)->get();
    $deals = \App\Models\Product::where('status', 'active')->whereNotNull('sale_price')
        ->whereColumn('sale_price', '<', 'price')->with('brand')->take(4)->get();
    $categories = \App\Models\Category::whereNull('parent_id')->orderBy('sort_order')->orderBy('name')->take(8)->get();
@endphp

{{-- ================= HERO ================= --}}
<section class="relative overflow-hidden bg-brand text-brand-fg">
    <div class="pointer-events-none absolute -right-24 -top-24 h-96 w-96 rounded-full bg-accent/20 blur-3xl"></div>
    <div class="pointer-events-none absolute bottom-0 left-1/3 h-72 w-72 rounded-full bg-white/5 blur-3xl"></div>

    <div class="container-x relative grid gap-12 py-16 lg:grid-cols-2 lg:py-24">
        <div data-reveal="slide-up" class="flex flex-col justify-center">
            <span class="pill w-fit !bg-white/10 !text-white/80">
                <x-ui.icon name="sparkles" class="w-3.5 h-3.5 text-accent" /> Trusted by researchers of horsepower everywhere
            </span>
            <h1 class="mt-5 text-4xl font-extrabold leading-[1.05] sm:text-5xl lg:text-6xl">
                Every Part.<br>Every Vehicle. <span class="text-accent">Fast.</span>
            </h1>
            <p class="mt-5 max-w-md text-lg text-white/70">
                Genuine and aftermarket auto parts from vetted sellers — matched to your exact
                year, make and model. Buyer protection and cashback on every order.
            </p>

            <div class="mt-8 rounded-2xl bg-white/10 p-2 ring-1 ring-white/10 backdrop-blur">
                @livewire('storefront.vehicle-selector')
            </div>

            <div class="mt-6 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm text-white/70">
                <span class="inline-flex items-center gap-1.5"><x-ui.icon name="check" class="w-4 h-4 text-accent" /> OEM &amp; part-number search</span>
                <span class="inline-flex items-center gap-1.5"><x-ui.icon name="check" class="w-4 h-4 text-accent" /> Escrow-protected payments</span>
                <span class="inline-flex items-center gap-1.5"><x-ui.icon name="check" class="w-4 h-4 text-accent" /> Verified sellers only</span>
            </div>
        </div>

        <div data-reveal="scale" class="relative hidden lg:block">
            <div class="grid h-full grid-cols-2 gap-4">
                @foreach ($featured->take(4) as $i => $p)
                    <a href="{{ route('products.show', $p->slug) }}"
                       class="group relative overflow-hidden rounded-2xl bg-white/10 ring-1 ring-white/10 {{ $i % 2 ? 'mt-8' : '' }}">
                        <img src="{{ $p->getFirstMediaUrl('images', 'thumb') ?: $p->getFirstMediaUrl('images') }}"
                             alt="{{ $p->title }}" class="aspect-square w-full object-cover transition group-hover:scale-105">
                        <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent p-3">
                            <p class="line-clamp-1 text-xs font-semibold text-white">{{ $p->title }}</p>
                            <p class="text-xs text-accent">${{ number_format($p->sale_price ?? $p->price, 2) }}</p>
                        </div>
                    </a>
                @endforeach
                @if ($featured->count() < 4)
                    <div class="col-span-2 grid place-items-center rounded-2xl bg-white/5 p-10 text-center text-sm text-white/50">
                        Seller listings appear here.
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- stat bar --}}
    <div class="border-t border-white/10 bg-black/20">
        <div class="container-x grid grid-cols-2 gap-6 py-8 text-center sm:grid-cols-4" data-reveal-group>
            @foreach ([
                ['listings', 'Parts Listed'], ['sellers', 'Verified Sellers'],
                ['makes', 'Vehicle Makes'], ['orders', 'Orders Fulfilled'],
            ] as [$key, $label])
                <div>
                    <p class="text-3xl font-extrabold text-white" data-counter-to="{{ $stats[$key] }}">0</p>
                    <p class="mt-1 text-xs uppercase tracking-wide text-white/50">{{ $label }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ================= CATEGORIES ================= --}}
<section class="container-x py-16" data-reveal>
    <div class="flex items-end justify-between">
        <div>
            <h2 class="text-2xl font-bold sm:text-3xl">Shop by category</h2>
            <p class="mt-1 text-sm text-muted">Everything from brake pads to alternators.</p>
        </div>
        <a href="{{ route('products.index') }}" class="hidden text-sm font-semibold text-accent hover:underline sm:inline-flex items-center gap-1">
            View all <x-ui.icon name="arrow-right" class="w-4 h-4" />
        </a>
    </div>

    <div class="mt-8 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4" data-reveal-group>
        @forelse ($categories as $cat)
            <a href="{{ route('products.index', ['category' => $cat->slug]) }}"
               class="group card flex items-center gap-4 p-5 transition-shadow hover:shadow-card-hover">
                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-accent/10 text-accent">
                    <x-ui.icon name="wrench" class="w-6 h-6" />
                </span>
                <div>
                    <p class="font-semibold text-fg group-hover:text-accent">{{ $cat->name }}</p>
                    <p class="text-xs text-muted">{{ $cat->children_count ?? $cat->children()->count() }} subcategories</p>
                </div>
            </a>
        @empty
            <p class="col-span-full text-sm text-muted">Categories will appear once the catalog is populated.</p>
        @endforelse
    </div>
</section>

{{-- ================= DEALS RAIL ================= --}}
@if ($deals->isNotEmpty())
<section class="bg-surface py-16">
    <div class="container-x" data-reveal>
        <div class="flex items-end justify-between">
            <h2 class="text-2xl font-bold sm:text-3xl">Today's deals</h2>
            <a href="{{ route('products.index', ['sort' => 'price_asc']) }}" class="text-sm font-semibold text-accent hover:underline">See all deals</a>
        </div>
        <div class="mt-8 grid grid-cols-2 gap-5 lg:grid-cols-4" data-reveal-group>
            @foreach ($deals as $product)
                <x-product-card :product="$product" />
            @endforeach
        </div>
    </div>
</section>
@endif

{{-- ================= FEATURED ================= --}}
<section class="container-x py-16" data-reveal>
    <div class="flex items-end justify-between">
        <h2 class="text-2xl font-bold sm:text-3xl">Fresh listings</h2>
        <a href="{{ route('products.index') }}" class="text-sm font-semibold text-accent hover:underline">Browse catalog</a>
    </div>
    @if ($featured->isNotEmpty())
        <div class="mt-8 grid grid-cols-2 gap-5 sm:grid-cols-3 lg:grid-cols-4" data-reveal-group>
            @foreach ($featured as $product)
                <x-product-card :product="$product" />
            @endforeach
        </div>
    @else
        <div class="mt-8 card grid place-items-center p-16 text-center">
            <x-ui.icon name="box" class="w-10 h-10 text-muted" />
            <p class="mt-3 font-semibold">No active listings yet</p>
            <p class="mt-1 text-sm text-muted">Once sellers are approved and publish parts, they'll show up here.</p>
        </div>
    @endif
</section>

{{-- ================= VALUE PROPS ================= --}}
<section class="border-y border-line bg-surface py-16">
    <div class="container-x grid gap-8 md:grid-cols-3" data-reveal-group>
        @foreach ([
            ['shield', 'Escrow-protected', 'Your payment is held until the part is delivered. Sellers are only paid once you have it in hand.'],
            ['sparkles', 'Cashback + credits', 'Earn a percentage back to your wallet on every completed order. Redeem at checkout.'],
            ['truck', 'Real fitment data', 'Every listing is matched to specific year/make/model so you order the right part the first time.'],
        ] as [$icon, $title, $body])
            <div class="flex gap-4">
                <span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl bg-accent/10 text-accent"><x-ui.icon :name="$icon" class="w-6 h-6" /></span>
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
    <div class="relative overflow-hidden rounded-3xl bg-brand p-10 text-center text-brand-fg sm:p-16" data-reveal="scale">
        <div class="pointer-events-none absolute -left-20 -top-20 h-72 w-72 rounded-full bg-accent/20 blur-3xl"></div>
        <h2 class="relative text-2xl font-bold sm:text-3xl">Have parts to sell?</h2>
        <p class="relative mx-auto mt-3 max-w-lg text-white/70">
            Reach buyers searching for the exact parts you stock. 10% flat commission, payouts released on delivery,
            AI-assisted listing tools included.
        </p>
        <a href="{{ route('two-factor.setup') }}" class="btn-accent relative mt-6">Start selling <x-ui.icon name="arrow-right" class="w-4 h-4" /></a>
    </div>
</section>
@endsection
