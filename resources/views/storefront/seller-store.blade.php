@extends('layouts.storefront')

@section('title', $sellerProfile->business_name . ' — Seller on ' . config('app.name'))
@section('meta_description', Str::limit(strip_tags($sellerProfile->bio ?: 'Auto parts from ' . $sellerProfile->business_name . ' on Panmotive.'), 155))

@php
    $theme = $sellerProfile->theme ?? [];
    $accentHex = ltrim($theme['accent'] ?? '', '#');
    $accentRgb = strlen($accentHex) === 6
        ? sprintf('%d %d %d', hexdec(substr($accentHex, 0, 2)), hexdec(substr($accentHex, 2, 2)), hexdec(substr($accentHex, 4, 2)))
        : null;
@endphp

@section('content')
<div class="container-x py-8 lg:py-12" @if ($accentRgb) style="--c-accent: {{ $accentRgb }}" @endif>
    @if ($sellerProfile->activeAnnouncement())
        <div class="mb-4 flex items-start gap-3 rounded-xl border border-accent/30 bg-accent/10 px-4 py-3 text-sm" data-reveal>
            <x-ui.icon name="sparkles" class="mt-0.5 w-4 h-4 text-fg" />
            <p>{{ $sellerProfile->activeAnnouncement() }}</p>
        </div>
    @endif

    {{-- header --}}
    <div class="card overflow-hidden" data-reveal>
        @if ($sellerProfile->banner_path)
            <img src="{{ \Illuminate\Support\Facades\Storage::url($sellerProfile->banner_path) }}" alt="" class="h-40 w-full object-cover sm:h-56">
        @else
            <div class="h-28 w-full bg-gradient-to-r from-brand to-brand/70 sm:h-36"></div>
        @endif
        <div class="p-6 sm:p-8">
        @if (!empty($theme['hero_headline']) || !empty($theme['hero_subtext']))
            <div class="mb-4">
                @if (!empty($theme['hero_headline']))
                    <p class="text-lg font-bold tracking-tight sm:text-xl">{{ $theme['hero_headline'] }}</p>
                @endif
                @if (!empty($theme['hero_subtext']))
                    <p class="mt-1 max-w-2xl text-sm text-muted">{{ $theme['hero_subtext'] }}</p>
                @endif
            </div>
        @endif
        <div class="-mt-14 flex flex-wrap items-start gap-6 sm:-mt-16">
            <div class="grid h-20 w-20 shrink-0 place-items-center rounded-2xl border-4 border-surface bg-brand text-3xl font-bold text-brand-fg">
                {{ Str::of($sellerProfile->business_name)->substr(0, 1)->upper() }}
            </div>
            <div class="min-w-0 flex-1 pt-3 sm:pt-[4.25rem]">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-2xl font-bold sm:text-3xl">{{ $sellerProfile->business_name }}</h1>
                    @if ($sellerProfile->hasBadge())
                        <span class="pill !bg-surface-2 !text-fg">{{ $sellerProfile->tierLabel() }}</span>
                    @endif
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted">
                    @if ($sellerProfile->rating_count > 0)
                        <span class="inline-flex items-center gap-1.5">
                            <x-ui.rating :value="$sellerProfile->rating" size="w-4 h-4" /> {{ number_format($sellerProfile->rating, 1) }} ({{ $sellerProfile->rating_count }} ratings)
                        </span>
                    @endif
                    <span>{{ $stats['listings'] }} active {{ Str::plural('listing', $stats['listings']) }}</span>
                    <span>Selling since {{ $stats['since']->format('M Y') }}</span>
                    @if ($reply = $sellerProfile->responseLabel())
                        <span class="inline-flex items-center gap-1"><x-ui.icon name="message" class="w-4 h-4" /> {{ $reply }}</span>
                    @endif
                    <span class="inline-flex items-center gap-1"><x-ui.icon name="refresh" class="w-4 h-4" /> {{ max((int) config('marketplace.returns.window_days', 30), (int) $sellerProfile->return_window_days) }}-day returns{{ $sellerProfile->return_shipping === 'seller' ? ' · free' : '' }}</span>
                </div>
                @php($about = $theme['about'] ?? $sellerProfile->bio)
                @if ($about)
                    <p class="mt-3 max-w-2xl whitespace-pre-line text-sm">{{ $about }}</p>
                @endif
                <div class="mt-4 flex flex-wrap items-center gap-3">
                    @livewire('storefront.follow-store', ['sellerId' => $sellerProfile->user_id])

                    @if ($sellerProfile->loyaltyEnabled())
                        <span class="inline-flex items-center gap-1.5 rounded-xl border border-accent/40 bg-surface-2 px-3 py-1.5 text-xs font-semibold text-fg">
                            <x-ui.icon name="star" class="w-4 h-4 fill-current" />
                            Earn {{ $sellerProfile->pointsPerDollar() }} points per $1
                        </span>
                    @endif

                    @if ($affiliateProgram)
                        <a href="{{ route('affiliate.join.form', $sellerProfile) }}" class="inline-flex items-center gap-1.5 text-xs font-semibold text-fg underline underline-offset-2">
                            <x-ui.icon name="sparkles" class="w-4 h-4" />
                            Join affiliate program &middot; {{ rtrim(rtrim(number_format($affiliateProgram->commission_pct, 2), '0'), '.') }}%
                        </a>
                    @endif
                </div>

                @auth
                    @if ($loyaltyAccount && $loyaltyAccount->points > 0)
                        <div class="mt-4 rounded-xl border border-line bg-surface-2 px-4 py-3 text-sm">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <span><span class="font-bold">{{ number_format($loyaltyAccount->points) }}</span> points here &middot; worth ${{ number_format($loyaltyAccount->points * ($sellerProfile->loyalty['redeem_cents_per_point'] ?? 1) / 100, 2) }}</span>
                                @php($minRedeem = $sellerProfile->loyalty['min_redeem'] ?? 500)
                                @if ($loyaltyAccount->points >= $minRedeem)
                                    <form method="POST" action="{{ route('loyalty.redeem', $sellerProfile) }}">
                                        @csrf
                                        <input type="hidden" name="points" value="{{ $loyaltyAccount->points }}">
                                        <button class="btn-accent btn-sm">Redeem to Panmotive credit</button>
                                    </form>
                                @else
                                    <span class="text-xs text-muted">{{ number_format($minRedeem) }} points minimum to redeem</span>
                                @endif
                            </div>
                        </div>
                    @endif
                @endauth
            </div>
        </div>

        @if ($sellerProfile->isOnVacation())
            <div class="mt-5 flex items-start gap-3 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm">
                <x-ui.icon name="clock" class="mt-0.5 w-4 h-4 text-amber-600" />
                <p>{{ $sellerProfile->vacation_message ?: 'This seller is currently away. Orders may take longer to ship.' }}
                    @if ($sellerProfile->vacation_until) <span class="font-semibold">Back {{ $sellerProfile->vacation_until->format('M j') }}.</span> @endif
                    @if ($sellerProfile->salesPaused()) <span class="font-semibold">New orders are paused until then.</span> @endif
                </p>
            </div>
        @endif

        @if ($sellerProfile->return_policy)
            <details class="mt-4 text-sm">
                <summary class="cursor-pointer font-medium text-accent">Return policy</summary>
                <p class="mt-2 whitespace-pre-wrap text-muted">{{ $sellerProfile->return_policy }}</p>
            </details>
        @endif
        </div>
    </div>

    @php($storeSale = \App\Models\SaleEvent::where('seller_id', $sellerProfile->user_id)->whereIn('status', ['scheduled', 'active'])
        ->where('starts_at', '<=', now())->where('ends_at', '>', now())->orderBy('ends_at')->first())
    @if ($storeSale)
        <div class="pm-grain relative mt-6 flex flex-wrap items-center justify-between gap-3 overflow-hidden rounded-2xl bg-gradient-to-r from-accent to-accent-pressed px-5 py-4 text-white" data-reveal="scale"
             x-data="{ left: {{ max(0, $storeSale->ends_at->getTimestamp() - now()->getTimestamp()) }} }"
             x-init="setInterval(() => left = Math.max(0, left - 1), 1000)">
            <div>
                <p class="text-xs font-semibold uppercase tracking-widest text-white/80">Store sale</p>
                <p class="text-xl font-bold">{{ $storeSale->name }} · {{ rtrim(rtrim(number_format($storeSale->percent_off, 2), '0'), '.') }}% off</p>
            </div>
            <p class="font-mono text-lg font-bold tabular-nums" aria-label="Time left">
                Ends in <span x-text="(Math.floor(left / 86400) > 0 ? Math.floor(left / 86400) + 'd ' : '') + String(Math.floor(left % 86400 / 3600)).padStart(2, '0') + ':' + String(Math.floor(left % 3600 / 60)).padStart(2, '0') + ':' + String(left % 60).padStart(2, '0')"></span>
            </p>
        </div>
    @endif

    @if ($offers->isNotEmpty())
        <div class="mt-4 flex flex-wrap gap-2" data-reveal>
            @foreach ($offers as $offer)
                <span class="inline-flex items-center gap-2 rounded-xl border border-dashed border-accent/50 bg-accent/5 px-3 py-1.5 text-sm">
                    <x-ui.icon name="tag" class="w-4 h-4 text-fg" />
                    <span class="font-mono font-semibold">{{ $offer->code }}</span>
                    <span class="text-muted">{{ $offer->type === 'percentage' ? rtrim(rtrim(number_format($offer->value,2),'0'),'.').'% off' : '$'.number_format($offer->value,2).' off' }}</span>
                </span>
            @endforeach
        </div>
    @endif

    @if (!empty($theme['sections']))
        <div class="mt-8 grid gap-4 sm:grid-cols-2" data-reveal-group>
            @foreach ($theme['sections'] as $section)
                <div class="card p-6" data-reveal>
                    @if (!empty($section['heading']))
                        <h3 class="font-bold">{{ $section['heading'] }}</h3>
                    @endif
                    @if (!empty($section['body']))
                        <p class="mt-2 whitespace-pre-line text-sm text-muted">{{ $section['body'] }}</p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if ($featured->isNotEmpty())
        <section class="mt-8" data-reveal>
            <h2 class="text-lg font-bold">Featured</h2>
            <div class="mt-4 grid gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($featured as $product)
                    <x-product-card :product="$product" />
                @endforeach
            </div>
        </section>
    @endif

    {{-- controls --}}
    <form method="GET" class="mt-8 flex flex-wrap items-center gap-3">
        <div class="relative">
            <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 w-4 h-4 -translate-y-1/2 text-muted" />
            <input name="q" value="{{ request('q') }}" placeholder="Search this store" class="input h-10 w-60 pl-9 text-sm">
        </div>
        <select name="sort" onchange="this.form.submit()" class="input h-10 w-auto text-sm">
            <option value="">Newest</option>
            <option value="price_asc" @selected(request('sort')==='price_asc')>Price: low to high</option>
            <option value="price_desc" @selected(request('sort')==='price_desc')>Price: high to low</option>
            <option value="rating" @selected(request('sort')==='rating')>Top rated</option>
        </select>
    </form>

    {{-- grid --}}
    <div class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        @forelse ($products as $product)
            <x-product-card :product="$product" />
        @empty
            <p class="col-span-full py-16 text-center text-sm text-muted">No products match.</p>
        @endforelse
    </div>

    <div class="mt-10">{{ $products->links() }}</div>
</div>
@endsection
