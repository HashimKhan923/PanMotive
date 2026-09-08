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
                'price' => (string) ($product->sale_price ?? $product->price),
                'availability' => $product->quantity > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'itemCondition' => 'https://schema.org/' . ucfirst($product->condition) . 'Condition',
            ],
        ], JSON_UNESCAPED_SLASHES) !!}
        </script>
    @endpush

    @php
        $media = $product->getMedia('images');
        $mainUrl = $media->first()?->getUrl('webp') ?: $product->getFirstMediaUrl('images') ?: '';
    @endphp

    <div class="container-x py-6 lg:py-10">
        <nav class="text-xs text-muted" data-reveal>
            <a href="{{ route('home') }}" class="hover:text-accent">Home</a> <span class="mx-1.5">/</span>
            <a href="{{ route('products.index') }}" class="hover:text-accent">Shop</a>
            @if ($product->category)
                <span class="mx-1.5">/</span>
                <a href="{{ route('products.index', ['category' => $product->category->slug]) }}" class="hover:text-accent">{{ $product->category->name }}</a>
            @endif
        </nav>

        <div class="mt-4 grid gap-10 lg:grid-cols-2" x-data="{ active: '{{ $mainUrl }}' }">
            {{-- gallery --}}
            <div data-reveal="slide-right">
                <div class="card aspect-square overflow-hidden">
                    <img :src="active" src="{{ $mainUrl }}" alt="{{ $product->title }}" width="800" height="800"
                         fetchpriority="high" class="h-full w-full object-cover">
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
                    <span class="text-sm font-semibold uppercase tracking-wide text-accent">{{ $product->brand->name }}</span>
                @endif
                <h1 class="mt-1 text-2xl font-bold leading-tight sm:text-3xl">{{ $product->title }}</h1>

                <div class="mt-2 flex flex-wrap items-center gap-3 text-sm text-muted">
                    @if ($product->seller?->sellerProfile?->rating > 0)
                        <x-ui.rating :value="$product->seller->sellerProfile->rating" :count="$product->seller->sellerProfile->rating_count" />
                        <span class="text-line">|</span>
                    @endif
                    <span class="capitalize">{{ $product->condition }}</span>
                    @if ($product->part_number)<span class="text-line">|</span> <span>Part #{{ $product->part_number }}</span>@endif
                    @if ($product->oem_number)<span class="text-line">|</span> <span>OEM #{{ $product->oem_number }}</span>@endif
                </div>

                <div class="mt-5 flex items-end gap-3">
                    <x-ui.price :price="$product->price" :sale="$product->sale_price" size="text-3xl" />
                </div>

                <div class="mt-3 flex items-center gap-2 text-sm">
                    @if ($product->quantity > 0)
                        <span class="inline-flex items-center gap-1.5 font-semibold text-emerald-600 dark:text-emerald-400">
                            <span class="h-2 w-2 rounded-full bg-emerald-500"></span> In stock
                        </span>
                        @if ($product->quantity <= 5)<span class="text-muted">— only {{ $product->quantity }} left</span>@endif
                    @else
                        <span class="font-semibold text-rose-500">Out of stock</span>
                    @endif
                </div>

                <p class="mt-2 inline-flex items-center gap-1.5 text-sm text-muted">
                    <x-ui.icon name="truck" class="w-4 h-4" /> Ships from {{ $product->seller?->sellerProfile?->business_name ?? 'a verified seller' }} · typically 2–5 business days
                </p>

                <div class="mt-6 flex gap-3">
                    <div class="flex-1">
                        @livewire('storefront.add-to-cart-button', ['product' => $product], key('atc-'.$product->id))
                    </div>
                    <button type="button" class="btn-outline !px-3.5" aria-label="Save for later" title="Save for later">
                        <x-ui.icon name="heart" class="w-5 h-5" />
                    </button>
                </div>

                <div class="mt-6 grid grid-cols-2 gap-3 rounded-2xl border border-line bg-surface p-4 text-xs text-muted">
                    <span class="inline-flex items-center gap-2"><x-ui.icon name="shield" class="w-4 h-4 text-accent" /> Escrow-protected payment</span>
                    <span class="inline-flex items-center gap-2"><x-ui.icon name="sparkles" class="w-4 h-4 text-accent" /> Cashback on delivery</span>
                    <span class="inline-flex items-center gap-2"><x-ui.icon name="check" class="w-4 h-4 text-accent" /> 30-day returns</span>
                    <span class="inline-flex items-center gap-2"><x-ui.icon name="check" class="w-4 h-4 text-accent" /> Verified seller</span>
                </div>
            </div>
        </div>

        {{-- ===== details ===== --}}
        <div class="mt-14 grid gap-12 lg:grid-cols-[1fr_340px]">
            <div class="space-y-12">
                @if ($product->description)
                    <section data-reveal>
                        <h2 class="text-lg font-bold">Description</h2>
                        <div class="prose-pm mt-3">{!! nl2br(e($product->description)) !!}</div>
                        @if (is_array($product->bullet_points) && count($product->bullet_points))
                            <ul class="mt-4 space-y-1.5 text-sm">
                                @foreach ($product->bullet_points as $bp)
                                    <li class="flex gap-2"><x-ui.icon name="check" class="mt-0.5 w-4 h-4 shrink-0 text-accent" /> {{ $bp }}</li>
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

                <section data-reveal>
                    <h2 class="text-lg font-bold">Reviews</h2>
                    <div class="mt-3 card grid place-items-center p-10 text-center text-sm text-muted">
                        <x-ui.icon name="star" class="w-8 h-8" />
                        <p class="mt-2 font-medium text-fg">No reviews yet</p>
                        <p>Verified buyers can review this part after delivery.</p>
                    </div>
                </section>
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
                                <p class="mt-0.5 text-xs font-bold text-accent">${{ number_format($r->sale_price ?? $r->price, 2) }}</p>
                            </div>
                        </a>
                    @empty
                        <p class="text-sm text-muted">No related parts yet.</p>
                    @endforelse
                </div>
            </aside>
        </div>
    </div>
</x-storefront-layout>
