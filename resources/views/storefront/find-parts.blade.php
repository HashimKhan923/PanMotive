@extends('layouts.storefront')
@section('title', 'Find parts for your vehicle')
@section('meta_description', 'Tell us your year, make and model — Panmotive shows only parts verified to fit.')

@section('content')
<div class="container-x py-10 lg:py-14">
    <div class="mx-auto max-w-2xl text-center" data-reveal>
        <h1 class="text-3xl font-bold tracking-tight sm:text-4xl">Find parts that fit — the first time</h1>
        <p class="mt-3 text-muted">Pick your vehicle once. Every result on Panmotive is then checked against your year, make, model and engine, so you never order the wrong part.</p>
    </div>

    <div class="mx-auto mt-8 max-w-2xl" data-reveal>
        @livewire('storefront.vehicle-selector')
    </div>

    <div class="mx-auto mt-6 max-w-2xl text-center text-sm text-muted" data-reveal>
        Prefer to browse? <a href="{{ route('products.index') }}" class="font-semibold text-fg underline underline-offset-2">See all parts →</a>
    </div>

    @if ($categories->isNotEmpty())
        <section class="mt-16" data-reveal>
            <h2 class="text-lg font-bold">Shop by part type</h2>
            <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($categories as $cat)
                    <a href="{{ route('products.index', ['category' => $cat->slug]) }}"
                       class="lift flex items-center gap-3 rounded-xl border border-line bg-surface p-4 text-sm font-medium hover:shadow-card">
                        <x-ui.icon name="box" class="w-6 h-6 shrink-0 text-fg" />
                        <span class="min-w-0 truncate">{{ $cat->name }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if ($makes->isNotEmpty())
        <section class="mt-14" data-reveal>
            <h2 class="text-lg font-bold">Popular makes</h2>
            <div class="mt-4 flex flex-wrap gap-2">
                @foreach ($makes as $make)
                    <a href="{{ route('products.index', ['make' => $make->slug]) }}"
                       class="rounded-full border border-line bg-surface px-4 py-1.5 text-sm hover:border-accent hover:text-accent">{{ $make->name }}</a>
                @endforeach
            </div>
        </section>
    @endif

    <section class="mt-16 grid gap-6 sm:grid-cols-3" data-reveal-group>
        @foreach ([
            ['search', 'Tell us your vehicle', 'Year, make, model, engine — or scan your VIN.'],
            ['shield', 'We verify fitment', 'Listings are matched against your exact configuration.'],
            ['truck', 'Order with confidence', 'Backed by Panmotive buyer protection and easy returns.'],
        ] as [$icon, $title, $body])
            <div class="card p-6" data-reveal>
                <span class="grid h-10 w-10 place-items-center rounded-xl bg-surface-2 text-fg"><x-ui.icon :name="$icon" class="w-6 h-6" /></span>
                <h3 class="mt-3 font-bold">{{ $title }}</h3>
                <p class="mt-1 text-sm text-muted">{{ $body }}</p>
            </div>
        @endforeach
    </section>
</div>
@endsection
