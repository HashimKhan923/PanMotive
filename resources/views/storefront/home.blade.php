@extends('layouts.storefront')

@section('title', 'Pan Motive — Every Part. Every Vehicle. Fast.')

@section('content')
@php($stats = cache()->remember('home.stats.'.now()->format('Y-m-d-H'), 3600, fn () => [
    'listings' => \App\Models\Product::where('status', 'active')->count(),
    'sellers' => \App\Models\SellerProfile::where('status', 'approved')->count(),
    'makes' => \App\Models\VehicleMake::count(),
]))

<section class="relative overflow-hidden bg-slate-900 px-4 py-24 text-white">
    <div class="mx-auto max-w-4xl text-center" data-reveal="slide-up">
        <h1 class="text-4xl font-bold sm:text-5xl">
            Every Part. Every Vehicle. <span class="text-amber-500">Fast.</span>
        </h1>
        <p class="mt-4 text-lg text-slate-300">
            Genuine and aftermarket auto parts, matched exactly to your vehicle.
        </p>
        <div class="mt-8">
            @livewire('storefront.vehicle-selector')
        </div>
        <a href="{{ route('products.index') }}" class="mt-6 inline-block text-sm text-amber-400 hover:text-amber-300">
            Or browse the full catalog →
        </a>
    </div>
</section>

<section class="border-b border-slate-100 py-12" data-reveal-group>
    <div class="mx-auto grid max-w-5xl grid-cols-3 gap-8 px-4 text-center">
        <div>
            <p class="text-4xl font-bold" data-counter-to="{{ $stats['listings'] }}">0</p>
            <p class="mt-1 text-sm text-slate-500">Active Listings</p>
        </div>
        <div>
            <p class="text-4xl font-bold" data-counter-to="{{ $stats['sellers'] }}">0</p>
            <p class="mt-1 text-sm text-slate-500">Trusted Sellers</p>
        </div>
        <div>
            <p class="text-4xl font-bold" data-counter-to="{{ $stats['makes'] }}">0</p>
            <p class="mt-1 text-sm text-slate-500">Vehicle Makes Supported</p>
        </div>
    </div>
</section>

<section class="mx-auto max-w-5xl px-4 py-16" data-reveal>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <h2 class="text-2xl font-semibold">Popular categories</h2>
        <a href="{{ route('products.index') }}" class="text-sm text-slate-500 hover:text-slate-900">View all</a>
    </div>
    <div class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-4" data-reveal-group>
        @foreach (\App\Models\Category::whereNull('parent_id')->orderBy('name')->limit(8)->get() as $category)
            <a href="{{ route('products.index') }}?category={{ $category->slug }}"
               class="rounded-xl border border-slate-100 p-4 text-sm font-medium hover:shadow-sm">
                {{ $category->name }}
            </a>
        @endforeach
    </div>
</section>
@endsection
