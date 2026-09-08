<x-storefront-layout>
    <x-slot name="title">{{ $product->meta_title ?: $product->title }}</x-slot>

    @push('structured_data')
        <script type="application/ld+json">
        {!! json_encode($product->structured_data ?: [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product->title,
        ]) !!}
        </script>
    @endpush

    <div class="mx-auto max-w-5xl px-4 py-12 sm:px-6 lg:px-8">
        <nav class="text-sm text-slate-500">
            <a href="{{ route('products.index') }}" class="hover:text-slate-900">Shop</a>
            <span class="mx-2">/</span>
            <span>{{ $product->category?->name }}</span>
        </nav>

        <div class="mt-6 grid gap-10 md:grid-cols-2">
            <div class="aspect-square overflow-hidden rounded-lg bg-slate-100">
                <img src="{{ $product->getFirstMediaUrl('images', 'webp') ?: $product->getFirstMediaUrl('images') }}"
                     alt="{{ $product->title }}" width="800" height="800" fetchpriority="high"
                     class="h-full w-full object-cover">
            </div>

            <div>
                <h1 class="text-2xl font-semibold">{{ $product->title }}</h1>
                @if ($product->brand)
                    <p class="mt-1 text-sm text-slate-500">{{ $product->brand->name }}</p>
                @endif

                <p class="mt-4 text-3xl font-bold">
                    ${{ number_format($product->sale_price ?? $product->price, 2) }}
                    @if ($product->sale_price)
                        <span class="ml-2 text-base font-normal text-slate-400 line-through">
                            ${{ number_format($product->price, 2) }}
                        </span>
                    @endif
                </p>

                <dl class="mt-4 space-y-1 text-sm text-slate-600">
                    @if ($product->part_number)
                        <div><dt class="inline font-medium">Part #:</dt> <dd class="inline">{{ $product->part_number }}</dd></div>
                    @endif
                    @if ($product->oem_number)
                        <div><dt class="inline font-medium">OEM #:</dt> <dd class="inline">{{ $product->oem_number }}</dd></div>
                    @endif
                    <div><dt class="inline font-medium">Condition:</dt> <dd class="inline capitalize">{{ $product->condition }}</dd></div>
                    <div><dt class="inline font-medium">In stock:</dt> <dd class="inline">{{ $product->quantity }}</dd></div>
                </dl>

                <div class="mt-6">
                    @livewire('storefront.add-to-cart-button', ['product' => $product], key('atc-'.$product->id))
                </div>
            </div>
        </div>

        @if ($product->description)
            <div class="prose mt-12 max-w-none">
                {!! nl2br(e($product->description)) !!}
            </div>
        @endif

        @php($recs = app(\App\Services\Recommendations\RecommendationService::class)->frequentlyBoughtWith($product->id))
        @if ($recs->isNotEmpty())
            <div class="mt-10" data-reveal>
                <h3 class="font-semibold mb-3">Frequently Bought Together</h3>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                    @foreach ($recs as $rec)
                        <a href="{{ route('products.show', $rec->slug) }}" class="border rounded-xl p-3 hover:shadow-lg transition">
                            <img src="{{ $rec->getFirstMediaUrl('images') }}" class="rounded-lg aspect-square object-cover mb-2">
                            <p class="text-sm line-clamp-2">{{ $rec->title }}</p>
                            <p class="text-amber-600 font-bold">${{ number_format($rec->sale_price ?? $rec->price, 2) }}</p>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($product->fitments->isNotEmpty())
            <section class="mt-12">
                <h2 class="text-lg font-semibold">Fits these vehicles</h2>
                <ul class="mt-3 grid gap-1 text-sm text-slate-600 sm:grid-cols-2">
                    @foreach ($product->fitments as $vehicle)
                        <li>{{ $vehicle->label() }}@if ($vehicle->pivot->position) — {{ $vehicle->pivot->position }}@endif</li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</x-storefront-layout>
