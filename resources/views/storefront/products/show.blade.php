<x-storefront-layout>
    <x-slot name="title">{{ $product->meta_title ?: $product->title }} — {{ config('app.name') }}</x-slot>
    <x-slot name="meta_description">{{ $product->meta_description ?: Str::limit(strip_tags($product->description), 155) }}</x-slot>

    @push('structured_data')
        <script type="application/ld+json">
        {!! json_encode($product->structured_data ?: [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product->title,
            'sku' => $product->sku,
            'mpn' => $product->part_number,
            'brand' => ['@type' => 'Brand', 'name' => $product->brand?->name],
            'offers' => [
                '@type' => 'Offer',
                'priceCurrency' => 'USD',
                'price' => (string) $product->effectivePrice(),
                'availability' => $product->quantity > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'itemCondition' => 'https://schema.org/' . ucfirst($product->condition) . 'Condition',
            ],
        ], JSON_UNESCAPED_SLASHES) !!}
        </script>
    @endpush

    @php
        $media = $product->getMedia('images');
        // Match AddToCartButton::mount()'s default-selection logic, so the gallery
        // opens on the right photo instead of flashing the generic one first.
        $defaultVariant = $product->activeVariants()->where('quantity', '>', 0)->first()
            ?? $product->activeVariants()->first();
        $mainUrl = $defaultVariant?->imageUrl('webp')
            ?: ($media->first()?->getUrl('webp') ?: $product->getFirstMediaUrl('images') ?: '');
    @endphp

    <div class="container-x py-6 lg:py-10">
        <nav class="text-xs text-muted" data-reveal>
            <a href="{{ route('home') }}" class="pm-tap relative hover:text-accent">Home</a> <span class="mx-1.5">/</span>
            <a href="{{ route('products.index') }}" class="pm-tap relative hover:text-accent">Shop</a>
            @if ($product->category)
                <span class="mx-1.5">/</span>
                <a href="{{ route('products.index', ['category' => $product->category->slug]) }}" class="hover:text-accent">{{ $product->category->name }}</a>
            @endif
        </nav>

        <div class="mt-4 grid gap-10 lg:grid-cols-2" x-data="{ active: '{{ $mainUrl }}' }"
             x-init="$watch('active', () => {
                 const img = document.querySelector('#pdp-hero-{{ $product->id }} img');
                 if (img && ! matchMedia('(prefers-reduced-motion: reduce)').matches)
                     img.animate([{ opacity: .2, transform: 'scale(1.03)', filter: 'blur(6px)' }, { opacity: 1, transform: 'scale(1)', filter: 'blur(0)' }],
                                 { duration: 420, easing: 'cubic-bezier(.22,1,.36,1)' });
             })"
             x-on:variant-image-changed.window="if ($event.detail.productId === {{ $product->id }}) active = $event.detail.url">
            {{-- gallery --}}
            <div data-reveal="slide-right">
                <div id="pdp-hero-{{ $product->id }}" class="card aspect-square overflow-hidden"
                     x-data="{ z: false, ox: '50%', oy: '50%' }"
                     @mousemove="const r = $el.getBoundingClientRect(); ox = ((($event.clientX - r.left) / r.width) * 100) + '%'; oy = ((($event.clientY - r.top) / r.height) * 100) + '%'"
                     @mouseenter="z = true" @mouseleave="z = false">
                    <img :src="active" src="{{ $mainUrl }}" alt="{{ $product->title }}" width="800" height="800"
                         data-vt-hero style="view-transition-name: product-hero"
                         fetchpriority="high" class="zoom-img h-full w-full object-cover"
                         :style="z ? `transform: scale(2); transform-origin: ${ox} ${oy}` : ''">
                </div>
                @if ($media->count() > 1)
                    <div class="mt-3 flex gap-3">
                        @foreach ($media as $m)
                            <button type="button" @click="active = '{{ $m->getUrl('webp') ?: $m->getUrl() }}'"
                                    class="h-16 w-16 shrink-0 overflow-hidden rounded-xl border-2 transition"
                                    :class="active === '{{ $m->getUrl('webp') ?: $m->getUrl() }}' ? 'border-accent' : 'border-line'">
                                <img src="{{ $m->getUrl('thumb') ?: $m->getUrl() }}" alt="" class="h-full w-full object-cover">
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- buy box --}}
            <div data-reveal="slide-up" class="flex flex-col">
                @if ($product->brand)
                    <span class="text-xs font-semibold uppercase tracking-[.14em] text-accent">{{ $product->brand->name }}</span>
                @endif
                <h1 class="mt-1.5 text-xl font-semibold leading-snug tracking-tight sm:text-[26px] sm:leading-[1.25]">{{ $product->title }}</h1>

                <div class="mt-2 flex flex-wrap items-center gap-3 text-sm text-muted">
                    @if ($product->seller?->sellerProfile?->rating > 0)
                        <x-ui.rating :value="$product->seller->sellerProfile->rating" :count="$product->seller->sellerProfile->rating_count" />
                        <span class="text-line">|</span>
                    @endif
                    <span class="capitalize">{{ $product->condition }}</span>
                    @if ($product->part_number)<span class="text-line">|</span> <span>Part #{{ $product->part_number }}</span>@endif
                    @if ($product->oem_number)<span class="text-line">|</span> <span>OEM #{{ $product->oem_number }}</span>@endif
                </div>

                @php($pv = $product->activeVariants)
                <div class="mt-5 flex items-end gap-3">
                    @if ($pv->isNotEmpty())
                        @php($lo = $pv->min(fn ($v) => $v->effectivePrice()))
                        @php($hi = $pv->max(fn ($v) => $v->effectivePrice()))
                        <span class="text-[26px] font-bold tracking-tight">
                            ${{ number_format($lo, 2) }}@if ($hi > $lo) <span class="text-muted">– ${{ number_format($hi, 2) }}</span>@endif
                        </span>
                    @else
                        <x-ui.price :price="$product->price" :sale="$product->liveSalePrice()" :basis="$product->priceBasis()" size="text-[26px] tracking-tight" />
                    @endif
                </div>

                @php($saleEvent = $product->activeSaleEvent())
                @php($dealEnds = $product->isOnDeal() ? $product->deal_ends_at : null)
                @if ($dealEnds)
                    <div class="pm-grain relative mt-3 flex flex-wrap items-center justify-between gap-2 overflow-hidden rounded-xl bg-gradient-to-r from-accent to-accent-pressed px-4 py-2.5 text-white"
                         x-data="{ left: {{ max(0, $dealEnds->getTimestamp() - now()->getTimestamp()) }},
                                   get d() { return Math.floor(this.left / 86400) }, get h() { return Math.floor(this.left % 86400 / 3600) },
                                   get m() { return Math.floor(this.left % 3600 / 60) }, get s() { return this.left % 60 } }"
                         x-init="setInterval(() => left = Math.max(0, left - 1), 1000)">
                        <span class="text-sm font-bold">{{ $saleEvent?->name ?? 'Limited-time deal' }}</span>
                        <span class="flex items-center gap-1 font-mono text-sm font-bold tabular-nums" aria-label="Time left">
                            <template x-if="d > 0"><span><span x-text="d"></span>d</span></template>
                            <span class="rounded bg-black/20 px-1.5"><span x-text="String(h).padStart(2, '0')"></span></span>:
                            <span class="rounded bg-black/20 px-1.5"><span x-text="String(m).padStart(2, '0')"></span></span>:
                            <span class="rounded bg-black/20 px-1.5"><span x-text="String(s).padStart(2, '0')"></span></span>
                        </span>
                    </div>
                @endif

                @php($pdpStock = $pv->isNotEmpty() ? $pv->sum('quantity') : $product->quantity)
                <div class="mt-3 flex items-center gap-2 text-sm">
                    @if ($pdpStock > 0)
                        <span class="inline-flex items-center gap-1.5 font-semibold text-emerald-600 dark:text-emerald-400">
                            <span class="h-2 w-2 rounded-full bg-emerald-500"></span> In stock
                        </span>
                        @if ($pdpStock <= 5)<span class="text-muted">— only {{ $pdpStock }} left</span>@endif
                    @else
                        <span class="font-semibold text-rose-500">Out of stock</span>
                    @endif
                </div>

                @php($sp = $product->seller?->sellerProfile)
                <p class="mt-2 inline-flex flex-wrap items-center gap-x-1.5 text-sm text-muted">
                    <x-ui.icon name="truck" class="w-4 h-4" /> Ships from
                    @if ($sp?->slug)
                        <a href="{{ route('sellers.show', $sp) }}" class="font-medium text-fg hover:text-accent">{{ $sp->business_name }}</a>
                    @else
                        {{ $sp?->business_name ?? 'a verified seller' }}
                    @endif
                    @php($handling = $product->effectiveHandlingDays())
                    · ships {{ $handling <= 0 ? 'same day' : 'within '.$handling.' business '.Str::plural('day', $handling) }}
                    @php($shipPolicy = app(\App\Services\Shipping\ShippingEstimateService::class)->policyForProduct($product))
                    @if ($shipPolicy?->method() === 'free')
                        · <span class="font-medium text-emerald-600">Free shipping</span>
                    @endif
                </p>
                @php($etaFrom = now()->addWeekdays(max(0, $handling) + 2))
                @php($etaTo = now()->addWeekdays(max(0, $handling) + 5))
                <p class="mt-1.5 inline-flex items-center gap-1.5 text-sm">
                    <x-ui.icon name="clock" class="w-4 h-4 text-fg" />
                    <span>Get it <strong>{{ $etaFrom->format('D, M j') }} – {{ $etaTo->format('D, M j') }}</strong></span>
                    <span class="text-xs text-muted">(estimate)</span>
                </p>
                @if ($shipPolicy?->excluded_states)
                    <p class="mt-1 text-xs text-muted">Doesn't ship to: {{ implode(', ', $shipPolicy->excluded_states) }}</p>
                @endif

                @if ($sp?->isOnVacation())
                    <p class="mt-2 inline-flex items-start gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-700 dark:text-amber-400">
                        <x-ui.icon name="clock" class="mt-0.5 w-4 h-4 shrink-0" />
                        {{ $sp->vacation_message ?: 'This seller is away — dispatch may be delayed.' }}
                        @if ($sp->vacation_until) (back {{ $sp->vacation_until->format('M j') }}) @endif
                        @if ($sp->salesPaused()) <strong>Not taking new orders until then.</strong> @endif
                    </p>
                @endif

                <div class="mt-5 flex gap-2.5" data-buy-anchor>
                    <div class="flex-1">
                        @livewire('storefront.add-to-cart-button', ['product' => $product], key('atc-'.$product->id))
                    </div>
                    <div class="shrink-0">
                        <x-ui.wishlist-heart :product-id="$product->id" size="lg" />
                    </div>
                </div>

                @if ($product->accepts_offers && $pv->isEmpty())
                    @livewire('storefront.make-offer', ['product' => $product], key('offer-'.$product->id))
                @endif

                @if ($product->quantity > 0 && $pv->isEmpty() && $product->subscription_enabled)
                    @php($snsPct = rtrim(rtrim(number_format((float) $product->subscription_discount_pct, 2), '0'), '.'))
                    <div class="mt-3 rounded-2xl border border-line bg-surface p-4" x-data="{ open: false }">
                        <button type="button" @click="open = !open" class="flex w-full items-center justify-between text-sm font-semibold">
                            <span class="inline-flex items-center gap-2">
                                <x-ui.icon name="refresh" class="w-4 h-4 text-fg" />
                                Subscribe &amp; Save {{ $snsPct }}%
                            </span>
                            <x-ui.icon name="chevron-down" class="w-4 h-4 transition" ::class="open && 'rotate-180'" />
                        </button>
                        <div x-show="open" x-cloak x-transition class="mt-3">
                            @auth
                                <form method="POST" action="{{ route('subscriptions.store') }}" class="flex flex-wrap items-end gap-2">
                                    @csrf
                                    <input type="hidden" name="product_id" value="{{ $product->id }}">
                                    <label class="text-xs text-muted">Qty
                                        <input type="number" name="quantity" value="1" min="1" max="20" class="input mt-1 h-9 w-16 py-0 text-sm">
                                    </label>
                                    <label class="text-xs text-muted">Deliver
                                        <select name="interval_days" class="input mt-1 h-9 w-auto py-0 text-sm">
                                            @foreach (\App\Models\Subscription::INTERVALS as $days => $label)
                                                <option value="{{ $days }}" @selected($days === 30)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <button class="btn-outline btn-sm">Start subscription</button>
                                </form>
                                <p class="mt-2 text-xs text-muted">Locks in {{ $snsPct }}% off every delivery. Skip or cancel anytime.</p>
                            @else
                                <p class="text-xs text-muted"><a href="{{ route('login') }}" class="text-fg underline underline-offset-2">Sign in</a> to set up recurring delivery and save {{ $snsPct }}% on every order.</p>
                            @endauth
                        </div>
                    </div>
                @endif

                <div class="mt-3 flex flex-wrap items-center gap-4">
                    <x-ui.compare-btn :product-id="$product->id" />
                    @livewire('storefront.message-seller', ['product' => $product], key('msg-'.$product->id))
                    <a href="{{ route('track.form') }}" class="inline-flex items-center gap-1.5 text-xs font-medium text-muted hover:text-fg">
                        <x-ui.icon name="truck" class="w-4 h-4" /> Track an order
                    </a>
                </div>

                @if ($sp && $sp->slug)
                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-line bg-surface p-4">
                        <div class="min-w-0">
                            <a href="{{ route('sellers.show', $sp) }}" class="font-semibold hover:text-accent">{{ $sp->business_name }}</a>
                            @if ($sp->hasBadge())
                                <span class="pill ml-1 !bg-surface-2 !text-fg">{{ $sp->tierLabel() }}</span>
                            @endif
                            @if ($sp->rating_count > 0)
                                <span class="ml-1 text-xs text-muted">{{ number_format($sp->rating, 1) }}★ ({{ $sp->rating_count }})</span>
                            @endif
                            @if ($reply = $sp->responseLabel())
                                <p class="mt-0.5 inline-flex items-center gap-1 text-xs text-muted"><x-ui.icon name="message" class="w-4 h-4" /> {{ $reply }}</p>
                            @endif
                        </div>
                        @livewire('storefront.follow-store', ['sellerId' => $product->seller_id], key('follow-'.$product->id))
                    </div>
                @endif

                {{-- Will this fit my vehicle? --}}
                @include('storefront.products.partials.fitment-check')

                @if ($product->volumeDiscounts->isNotEmpty())
                    <div class="mt-4 rounded-2xl border border-line bg-surface p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-muted">Buy more, save more</p>
                        <div class="mt-2 flex flex-wrap gap-2 text-sm">
                            @foreach ($product->volumeDiscounts as $tier)
                                <span class="rounded-lg bg-surface-2 px-2.5 py-1">
                                    {{ $tier->min_quantity }}+ →
                                    <span class="font-semibold">${{ number_format($tier->unitPrice($product->effectivePrice()), 2) }}</span> ea
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($bundles->isNotEmpty())
                    <div class="mt-4 space-y-2">
                        @foreach ($bundles as $bundle)
                            <div class="rounded-2xl border border-dashed border-accent/40 bg-accent/5 p-4">
                                <p class="text-sm font-semibold">{{ $bundle->title }}</p>
                                <p class="mt-1 text-xs text-muted">{{ $bundle->products->pluck('title')->join(' + ') }}</p>
                                <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                                    <p class="text-sm">
                                        <span class="text-muted line-through">${{ number_format($bundle->fullPrice(), 2) }}</span>
                                        <span class="ml-1 font-bold text-accent">${{ number_format($bundle->discountedPrice(), 2) }}</span>
                                        <span class="ml-1 text-xs text-emerald-600">save ${{ number_format($bundle->savings(), 2) }}</span>
                                    </p>
                                    @if ($bundle->products->contains(fn ($p) => $p->hasVariants()))
                                        <span class="text-xs text-muted">Add each item to pick its options</span>
                                    @else
                                        <form method="POST" action="{{ route('cart.add-bundle', $bundle) }}">
                                            @csrf
                                            <button class="btn-accent btn-sm"><x-ui.icon name="cart" class="w-4 h-4" /> Add bundle to cart</button>
                                        </form>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="mt-6 grid grid-cols-2 gap-3 rounded-2xl border border-line bg-surface p-4 text-xs text-muted">
                    <span class="inline-flex items-center gap-2"><x-ui.icon name="shield" class="w-4 h-4 text-fg" /> Escrow-protected payment</span>
                    <span class="inline-flex items-center gap-2"><x-ui.icon name="sparkles" class="w-4 h-4 text-fg" /> Cashback on delivery</span>
                    <span class="inline-flex items-center gap-2"><x-ui.icon name="check" class="w-4 h-4 text-fg" /> {{ max((int) config('marketplace.returns.window_days', 30), (int) ($sp?->return_window_days ?? 30)) }}-day returns{{ $sp?->return_shipping === 'seller' ? ' · free' : '' }}</span>
                    <span class="inline-flex items-center gap-2"><x-ui.icon name="check" class="w-4 h-4 text-fg" /> Verified seller</span>
                </div>
            </div>
        </div>

        {{-- ===== details ===== --}}
        <div class="mt-14 grid gap-12 lg:grid-cols-[1fr_340px]">
            <div class="space-y-12">
                @if ($product->description)
                    <section data-reveal>
                        <h2 class="text-lg font-bold">Description</h2>
                        <x-ui.rich-text :content="$product->description" class="mt-3" />
                        @if (is_array($product->bullet_points) && count($product->bullet_points))
                            <ul class="mt-4 space-y-1.5 text-sm">
                                @foreach ($product->bullet_points as $bp)
                                    <li class="flex gap-2"><x-ui.icon name="check" class="mt-0.5 w-4 h-4 shrink-0 text-fg" /> {{ $bp }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                @endif

                @if ($product->attributeValues->isNotEmpty())
                    <section data-reveal>
                        <h2 class="text-lg font-bold">Specifications</h2>
                        <dl class="mt-3 divide-y divide-line overflow-hidden rounded-xl border border-line text-sm">
                            @foreach ($product->attributeValues as $av)
                                <div class="flex justify-between gap-4 bg-surface px-4 py-2.5">
                                    <dt class="text-muted">{{ $av->attribute?->name }}</dt>
                                    <dd class="font-medium">{{ $av->value }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>
                @endif

                <section data-reveal>
                    <h2 class="text-lg font-bold">Vehicle fitment</h2>
                    @if ($product->fitments->isNotEmpty())
                        <div class="mt-3 overflow-x-auto rounded-xl border border-line">
                            <table class="w-full text-sm">
                                <thead class="bg-surface-2 text-left text-xs uppercase text-muted">
                                    <tr><th class="px-4 py-2">Year</th><th class="px-4 py-2">Make</th><th class="px-4 py-2">Model</th><th class="px-4 py-2">Engine</th><th class="px-4 py-2">Position</th></tr>
                                </thead>
                                <tbody class="divide-y divide-line">
                                    @foreach ($product->fitments as $v)
                                        <tr class="bg-surface">
                                            <td class="px-4 py-2">{{ $v->year }}</td>
                                            <td class="px-4 py-2">{{ $v->make?->name }}</td>
                                            <td class="px-4 py-2">{{ $v->model?->name }}</td>
                                            <td class="px-4 py-2">{{ $v->engine?->name ?? '—' }}</td>
                                            <td class="px-4 py-2">{{ $v->pivot->position ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="mt-3 text-sm text-muted">The seller hasn't published specific fitment data for this part. Contact them to confirm compatibility with your vehicle.</p>
                    @endif
                </section>

                @livewire('storefront.product-reviews', ['product' => $product], key('reviews-'.$product->id))

                @livewire('storefront.product-questions', ['product' => $product], key('qa-'.$product->id))
            </div>

            {{-- related --}}
            <aside data-reveal>
                <h2 class="text-sm font-bold uppercase tracking-wide text-muted">Frequently bought together</h2>
                @php($recs = app(\App\Services\Recommendations\RecommendationService::class)->frequentlyBoughtWith($product->id))
                <div class="mt-4 space-y-3">
                    @forelse ($recs->isNotEmpty() ? $recs : $related as $r)
                        <a href="{{ route('products.show', $r->slug) }}" class="flex gap-3 rounded-xl border border-line bg-surface p-3 hover:shadow-card">
                            <img src="{{ $r->getFirstMediaUrl('images', 'thumb') ?: $r->getFirstMediaUrl('images') }}" alt="" class="h-14 w-14 shrink-0 rounded-lg object-cover">
                            <div class="min-w-0">
                                <p class="line-clamp-2 text-xs font-medium">{{ $r->title }}</p>
                                <p class="mt-0.5 text-xs font-bold text-accent">${{ number_format($r->effectivePrice(), 2) }}</p>
                            </div>
                        </a>
                    @empty
                        <p class="text-sm text-muted">No related parts yet.</p>
                    @endforelse
                </div>
            </aside>
        </div>

        @php($alsoViewed = app(\App\Services\Recommendations\RecommendationService::class)->alsoViewed($product->id))
        @if ($alsoViewed->isNotEmpty())
            <section class="mt-16" data-reveal>
                <h2 class="text-lg font-bold">Customers also viewed</h2>
                <div class="mt-4 grid gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    @foreach ($alsoViewed as $r)
                        <x-product-card :product="$r" wire:key="alsoviewed-{{ $r->id }}" />
                    @endforeach
                </div>
            </section>
        @endif

        @if ($recent->isNotEmpty())
            <section class="mt-16" data-reveal>
                <h2 class="text-lg font-bold">Recently viewed</h2>
                <div class="mt-4 grid gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    @foreach ($recent as $r)
                        <x-product-card :product="$r" wire:key="recent-{{ $r->id }}" />
                    @endforeach
                </div>
            </section>
        @endif
    </div>
    @if ($pdpStock > 0 && ! $sp?->salesPaused())
        <div data-sticky-buy aria-hidden="true" x-data
             class="pm-sticky-buy fixed inset-x-0 bottom-0 z-40 border-t border-line bg-surface/90 backdrop-blur-xl">
            <div class="container-x flex items-center gap-3 py-2.5">
                <img src="{{ $mainUrl }}" alt="" class="h-10 w-10 shrink-0 rounded-lg object-cover">
                <p class="hidden min-w-0 flex-1 truncate text-sm font-semibold sm:block">{{ $product->title }}</p>
                <span class="ml-auto text-lg font-bold sm:ml-0">${{ number_format($pv->isNotEmpty() ? $pv->min(fn ($v) => $v->effectivePrice()) : $product->effectivePrice(), 2) }}</span>
                <button type="button" tabindex="-1" class="btn-accent btn-sm"
                        @click="document.querySelector('[data-buy-anchor]').scrollIntoView({ behavior: 'smooth', block: 'center' });
                                setTimeout(() => document.querySelector('[data-buy-anchor] button.btn-accent')?.click(), 450)">
                    <x-ui.icon name="cart" class="w-4 h-4" /> {{ $pv->isNotEmpty() ? 'Choose option' : 'Add to cart' }}
                </button>
            </div>
        </div>
    @endif
</x-storefront-layout>
