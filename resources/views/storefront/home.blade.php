<x-storefront-layout>
    <section class="mx-auto max-w-7xl px-4 py-20 sm:px-6 lg:px-8" data-reveal>
        <h1 class="max-w-2xl text-4xl font-bold tracking-tight sm:text-5xl">
            Auto parts for every vehicle.
        </h1>
        <p class="mt-4 max-w-xl text-lg text-slate-600">
            Genuine and aftermarket parts, fitted to your exact make, model and year.
        </p>
        <div class="mt-8 flex gap-3">
            <a href="{{ route('products.index') }}"
               class="rounded-md bg-slate-900 px-5 py-2.5 text-white hover:bg-slate-700">
                Browse the shop
            </a>
            <a href="{{ route('cart.index') }}"
               class="rounded-md border border-slate-300 px-5 py-2.5 hover:bg-slate-50">
                View cart
            </a>
        </div>
    </section>
</x-storefront-layout>
