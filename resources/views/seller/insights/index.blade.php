@extends('layouts.seller')

@section('title', 'Insights')

@section('content')
<div class="max-w-5xl space-y-6">
    <div>
        <h1 class="text-2xl font-bold">Listing insights</h1>
        <p class="mt-1 text-sm text-muted">Where the money's leaking — and quick fixes.</p>
    </div>
    @if (session('error'))<p class="rounded-xl bg-rose-500/10 px-4 py-3 text-sm text-rose-600">{{ session('error') }}</p>@endif
    @if (session('success'))<p class="rounded-xl bg-emerald-500/10 px-4 py-3 text-sm text-emerald-600">{{ session('success') }}</p>@endif

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- Traffic, no sales --}}
        <div class="card p-6" data-reveal>
            <h2 class="font-bold">Looked at, not bought</h2>
            <p class="mt-1 text-xs text-muted">20+ shoppers in 30 days, no sale. Check price, photos and fitment.</p>
            @forelse ($lookers as $p)
                <div class="mt-3 border-t border-line pt-3">
                    <div class="flex items-center justify-between gap-3">
                        <a href="{{ route('seller.products.edit', $p) }}" class="line-clamp-1 text-sm font-medium hover:text-accent">{{ $p->title }}</a>
                        <span class="shrink-0 text-xs text-muted">{{ $p->recent_visitors }} shoppers</span>
                    </div>
                    @php($missing = collect($p->quality['checks'])->where('ok', false)->pluck('label'))
                    <p class="mt-0.5 text-xs {{ $missing->isNotEmpty() ? 'text-amber-600' : 'text-muted' }}">
                        {{ $missing->isNotEmpty() ? 'Missing: '.$missing->take(3)->implode(', ') : 'Listing looks complete — compare your price with similar listings.' }}
                    </p>
                </div>
            @empty
                <p class="mt-4 text-sm text-muted">No listings with traffic and zero sales.</p>
            @endforelse
        </div>

        {{-- Low quality --}}
        <div class="card p-6" data-reveal>
            <h2 class="font-bold">Quick listing fixes</h2>
            <p class="mt-1 text-xs text-muted">Listing quality below 60/100 — complete listings rank higher and sell more.</p>
            @forelse ($lowQuality as $p)
                <div class="mt-3 flex items-center justify-between gap-3 border-t border-line pt-3">
                    <div class="min-w-0">
                        <a href="{{ route('seller.products.edit', $p) }}" class="line-clamp-1 text-sm font-medium hover:text-accent">{{ $p->title }}</a>
                        <p class="text-xs text-muted">Add: {{ collect($p->quality['checks'])->where('ok', false)->pluck('label')->take(3)->implode(', ') }}</p>
                    </div>
                    <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold {{ $p->quality['score'] < 40 ? 'bg-rose-500/10 text-rose-500' : 'bg-amber-500/10 text-amber-600' }}">{{ $p->quality['score'] }}/100</span>
                </div>
            @empty
                <p class="mt-4 text-sm text-muted">Every active listing scores 60 or better.</p>
            @endforelse
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        {{-- Stale --}}
        <div class="card p-6" data-reveal>
            <h2 class="font-bold">Not selling</h2>
            <p class="mt-1 text-xs text-muted">Active 45+ days, no sale in 60. A 14-day markdown often gets them moving.</p>
            @forelse ($stale as $p)
                <div class="mt-3 flex items-center justify-between gap-3 border-t border-line pt-3" wire:key="stale-{{ $p->id }}">
                    <div class="min-w-0">
                        <a href="{{ route('seller.products.edit', $p) }}" class="line-clamp-1 text-sm font-medium hover:text-accent">{{ $p->title }}</a>
                        <p class="text-xs text-muted">${{ number_format($p->price, 2) }} · {{ $p->views_count }} views · {{ $p->quantity }} in stock</p>
                    </div>
                    <form method="POST" action="{{ route('seller.insights.run-sale', $p) }}">
                        @csrf
                        <input type="hidden" name="pct" value="15">
                        <button class="btn-accent btn-sm shrink-0" title="15% off for 14 days — ends automatically">Run 15% sale</button>
                    </form>
                </div>
            @empty
                <p class="mt-4 text-sm text-muted">Nothing stale — nice.</p>
            @endforelse
        </div>

        {{-- Hot low stock --}}
        <div class="card p-6" data-reveal>
            <h2 class="font-bold">Restock now</h2>
            <p class="mt-1 text-xs text-muted">High interest, nearly gone.</p>
            @forelse ($hotLowStock as $p)
                <div class="mt-3 flex items-center justify-between gap-3 border-t border-line pt-3">
                    <a href="{{ route('seller.products.edit', $p) }}" class="line-clamp-1 text-sm font-medium hover:text-accent">{{ $p->title }}</a>
                    <span class="shrink-0 rounded-full bg-amber-500/10 px-2 py-0.5 text-xs font-semibold text-amber-600">{{ $p->quantity }} left · {{ $p->recent_views }} views (30d)</span>
                </div>
            @empty
                <p class="mt-4 text-sm text-muted">Stock levels look healthy.</p>
            @endforelse
        </div>

        {{-- Unmet demand --}}
        <div class="card p-6" data-reveal>
            <h2 class="font-bold">Buyers want it — you're out</h2>
            <p class="mt-1 text-xs text-muted">Out-of-stock listings on wishlists.</p>
            @forelse ($demand as $p)
                <div class="mt-3 flex items-center justify-between gap-3 border-t border-line pt-3">
                    <a href="{{ route('seller.products.edit', $p->id) }}" class="line-clamp-1 text-sm font-medium hover:text-accent">{{ $p->title }}</a>
                    <span class="shrink-0 text-xs text-muted">{{ $p->want_count }} waiting</span>
                </div>
            @empty
                <p class="mt-4 text-sm text-muted">No unmet demand tracked.</p>
            @endforelse
        </div>

        {{-- Unanswered --}}
        <div class="card p-6" data-reveal>
            <h2 class="font-bold">Unanswered questions <a href="{{ route('seller.questions.index') }}" class="ml-1 text-xs font-medium text-accent hover:underline">Answer →</a></h2>
            <p class="mt-1 text-xs text-muted">Answering lifts conversion.</p>
            @forelse ($questions as $q)
                <div class="mt-3 border-t border-line pt-3">
                    <a href="{{ route('products.show', $q->product->slug) }}#questions" target="_blank" class="text-sm hover:text-accent">“{{ Str::limit($q->body, 80) }}”</a>
                    <p class="text-xs text-muted">on {{ $q->product->title }}</p>
                </div>
            @empty
                <p class="mt-4 text-sm text-muted">All caught up.</p>
            @endforelse
        </div>
    </div>
</div>
@endsection
