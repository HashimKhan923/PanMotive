@extends('layouts.storefront')

@section('title', "Today's deals — " . config('app.name'))
@section('meta_description', 'Live discounts on brake kits, filters, sensors and more — verified sellers, escrow-protected checkout, cashback on delivery.')

@section('content')
<div class="container-x py-8 lg:py-12">
    <div class="flex items-center gap-3" data-reveal>
        <span class="pill !bg-surface-2 !text-fg"><x-ui.icon name="sparkles" class="w-4 h-4" /> Live deals</span>
        <h1 class="text-2xl font-bold sm:text-3xl">Today's deals</h1>
    </div>
    <p class="mt-1 text-sm text-muted" data-reveal>Prices drop while stock lasts. {{ $deals->total() }} parts on sale right now.</p>

    @if ($dealOfDay)
        @php
            $pct = $dealOfDay->price > 0 ? round(($dealOfDay->price - $dealOfDay->sale_price) / $dealOfDay->price * 100) : 0;
        @endphp
        <div class="mt-6 grid gap-6 overflow-hidden rounded-3xl bg-brand p-6 text-brand-fg sm:grid-cols-2 sm:p-8" data-reveal="scale">
            <div class="flex flex-col justify-center">
                <span class="text-xs font-semibold uppercase tracking-widest text-accent">Deal of the day</span>
                <a href="{{ route('products.show', $dealOfDay->slug) }}" class="mt-2 text-xl font-bold leading-tight hover:underline sm:text-2xl">{{ $dealOfDay->title }}</a>
                <div class="mt-3 flex items-end gap-3">
                    <span class="text-3xl font-bold">${{ number_format($dealOfDay->sale_price, 2) }}</span>
                    <span class="text-white/80 line-through">${{ number_format($dealOfDay->price, 2) }}</span>
                    <span class="pill !bg-accent !text-accent-fg">-{{ $pct }}%</span>
                </div>
                @if ($dealOfDay->deal_ends_at)
                    <div class="mt-4" x-data="countdown('{{ $dealOfDay->deal_ends_at->toIso8601String() }}')" x-init="start()">
                        <p class="text-xs text-white/80">Ends in</p>
                        <p class="mt-1 font-mono text-lg font-bold" x-text="display"></p>
                    </div>
                @endif
                <a href="{{ route('products.show', $dealOfDay->slug) }}" class="btn-accent mt-5 w-max">Grab this deal</a>
            </div>
            <a href="{{ route('products.show', $dealOfDay->slug) }}" class="block overflow-hidden rounded-2xl bg-white/10">
                <img src="{{ $dealOfDay->getFirstMediaUrl('images') }}" alt="{{ $dealOfDay->title }}" class="h-full w-full object-cover">
            </a>
        </div>
    @endif

    @if ($deals->isEmpty())
        <div class="mt-10 card grid place-items-center p-16 text-center">
            <x-ui.icon name="sparkles" class="w-12 h-12 text-muted" />
            <p class="mt-4 text-lg font-semibold">No active deals</p>
            <p class="mt-1 text-sm text-muted">Check back soon — sellers refresh discounts daily.</p>
            <a href="{{ route('products.index') }}" class="btn-accent mt-6">Browse all parts</a>
        </div>
    @else
        <div class="mt-8 grid gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4" data-reveal-group>
            @foreach ($deals as $product)
                <div wire:key="deal-{{ $product->id }}" class="relative">
                    <x-product-card :product="$product" />
                    @if ($product->deal_ends_at)
                        <div class="mt-1 text-center text-xs font-medium text-accent"
                             x-data="countdown('{{ $product->deal_ends_at->toIso8601String() }}')" x-init="start()">
                            <span x-text="'Ends in ' + display"></span>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="mt-10">{{ $deals->links() }}</div>
    @endif
</div>

@push('scripts')
<script @nonce>
    document.addEventListener('alpine:init', () => {
        Alpine.data('countdown', (endsAt) => ({
            display: '',
            timer: null,
            start() {
                this.tick();
                this.timer = setInterval(() => this.tick(), 1000);
            },
            tick() {
                const diff = new Date(endsAt).getTime() - Date.now();
                if (diff <= 0) {
                    this.display = 'Ended';
                    clearInterval(this.timer);
                    return;
                }
                const d = Math.floor(diff / 86400000);
                const h = Math.floor((diff % 86400000) / 3600000);
                const m = Math.floor((diff % 3600000) / 60000);
                const s = Math.floor((diff % 60000) / 1000);
                this.display = (d > 0 ? d + 'd ' : '') + String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
            },
            destroy() { clearInterval(this.timer); },
        }));
    });
</script>
@endpush
@endsection
