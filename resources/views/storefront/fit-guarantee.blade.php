@extends('layouts.storefront')
@section('title', 'Panmotive Fit Guarantee')
@section('meta_description', 'If a part listed for your vehicle doesn’t fit, you get a full refund and free return shipping. Here is how the Panmotive Fit Guarantee works.')

@section('content')
<div class="container-x max-w-3xl py-12">
    <div class="text-center" data-reveal>
        <span class="pill !bg-surface-2 !text-fg">Buyer protection</span>
        <h1 class="mt-3 text-3xl font-bold tracking-tight sm:text-4xl">Panmotive <span class="text-accent">Fit Guarantee</span></h1>
        <p class="mx-auto mt-3 max-w-xl text-muted">Buy the part listed for your vehicle. If it doesn't fit, you get a full refund, and the return shipping is free for you.</p>
    </div>

    <div class="mt-10 grid gap-4 sm:grid-cols-3" data-reveal-group>
        @foreach ([
            ['vehicle', '1. Add your vehicle', 'Choose your year, make and model. We keep it in your garage.'],
            ['check', '2. Look for "Fits your vehicle"', 'The green check means the seller listed the part for your exact vehicle.'],
            ['shield', '3. You’re covered', 'If it doesn’t fit, request a return and pick “Doesn’t fit — the listing said it fits my vehicle”.'],
        ] as [$icon, $title, $text])
            <div class="card p-5">
                <x-ui.icon :name="$icon" class="h-6 w-6 text-accent" />
                <p class="mt-3 font-semibold">{{ $title }}</p>
                <p class="mt-1 text-sm text-muted">{{ $text }}</p>
            </div>
        @endforeach
    </div>

    <div class="card mt-8 p-6 sm:p-8" data-reveal>
        <h2 class="text-lg font-bold">What you get</h2>
        <ul class="mt-4 space-y-3 text-sm">
            @foreach ([
                'A full refund of what you paid for the part.',
                'Free return shipping. The seller pays for it, not you.',
                'The seller can’t simply say no. If they disagree, Panmotive looks at it and decides.',
                'It works for the whole return window shown on the listing (at least '.(int) config('marketplace.returns.window_days', 30).' days after delivery).',
            ] as $line)
                <li class="flex items-start gap-3"><x-ui.icon name="check" class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" /><span>{{ $line }}</span></li>
            @endforeach
        </ul>

        <h2 class="mt-8 text-lg font-bold">When it applies</h2>
        <ul class="mt-4 space-y-3 text-sm">
            @foreach ([
                'The part showed “Fits your vehicle” for the vehicle in your garage when you ordered it.',
                'The part was not installed in a way that damaged it, and you send it back with its parts.',
                'You start the return within the return window.',
            ] as $line)
                <li class="flex items-start gap-3"><x-ui.icon name="info" class="mt-0.5 h-4 w-4 shrink-0 text-muted" /><span>{{ $line }}</span></li>
            @endforeach
        </ul>

        <h2 class="mt-8 text-lg font-bold">When it doesn’t</h2>
        <ul class="mt-4 space-y-3 text-sm text-muted">
            <li>Parts that said “Fit not confirmed” for your vehicle. You can still return them under the seller's normal return policy.</li>
            <li>If you ordered for a different vehicle than the one in your garage. Use “I ordered the wrong part” instead.</li>
        </ul>
    </div>

    <div class="mt-8 flex flex-wrap justify-center gap-3" data-reveal>
        <a href="{{ route('home') }}#garage" class="btn-accent">Add your vehicle</a>
        <a href="{{ route('orders.index') }}" class="btn-outline">Start a return</a>
    </div>
</div>
@endsection
