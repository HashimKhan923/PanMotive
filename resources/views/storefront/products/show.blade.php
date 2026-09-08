<x-storefront-layout>
    <x-slot name="title">{{ $product->meta_title ?: $product->title }}</x-slot>

    <div class="mx-auto max-w-5xl px-4 py-12 sm:px-6 lg:px-8">
        <nav class="text-sm text-slate-500">
            <a href="{{ route('products.index') }}" class="hover:text-slate-900">Shop</a>
            <span class="mx-2">/</span>
            <span>{{ $product->category?->name }}</span>
        </nav>

        <div class="mt-6 grid gap-10 md:grid-cols-2">
            <div class="aspect-square overflow-hidden rounded-lg bg-slate-100">
                <img src="{{ $product->getFirstMediaUrl('images') }}" alt="{{ $product->title }}"
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

                <form method="POST" action="{{ route('cart.add') }}" class="mt-6">
                    @csrf
                    <input type="hidden" name="product_id" value="{{ $product->id }}">
                    <button type="submit"
                            class="w-full rounded-md bg-slate-900 px-5 py-3 text-white hover:bg-slate-700">
                        Add to cart
                    </button>
                </form>
            </div>
        </div>

        @if ($product->description)
            <div class="prose mt-12 max-w-none">
                {!! nl2br(e($product->description)) !!}
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
