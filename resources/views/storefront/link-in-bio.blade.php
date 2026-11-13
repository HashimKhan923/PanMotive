<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $sp->business_name }} · Panmotive</title>
    <meta name="description" content="Shop {{ $sp->business_name }} on Panmotive: {{ $bio['headline'] }}.">
    <meta property="og:title" content="{{ $sp->business_name }}">
    <meta property="og:description" content="{{ $bio['headline'] }}">
    @if ($featured->first()?->getFirstMediaUrl('images'))<meta property="og:image" content="{{ $featured->first()->getFirstMediaUrl('images') }}">@endif
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-[#F5F6F8] text-[#15191E] antialiased">
<main class="mx-auto max-w-md px-4 pb-16 pt-8">
    <header class="text-center">
        @if ($logo)
            <img src="{{ $logo }}" alt="{{ $sp->business_name }}" class="mx-auto h-20 w-20 rounded-full border-4 border-white object-cover shadow">
        @else
            <div class="mx-auto grid h-20 w-20 place-items-center rounded-full text-2xl font-extrabold text-white shadow" style="background: {{ $color }}">{{ mb_strtoupper(mb_substr($sp->business_name, 0, 1)) }}</div>
        @endif
        <h1 class="mt-3 text-xl font-bold">{{ $sp->business_name }}</h1>
        @if ((int) $sp->rating_count > 0)
            <p class="mt-0.5 text-sm text-[#5E6672]">★ {{ number_format((float) $sp->rating, 1) }} · {{ $sp->rating_count }} {{ \Illuminate\Support\Str::plural('rating', (int) $sp->rating_count) }}</p>
        @endif
        <p class="mt-2 text-sm font-medium">{{ $bio['headline'] }}</p>
    </header>

    <a href="{{ $link(null) }}" class="mt-6 block rounded-2xl px-5 py-4 text-center font-semibold text-white shadow transition hover:opacity-90" style="background: {{ $color }}">Shop all parts →</a>

    @if ($coupon)
        <div class="mt-4 rounded-2xl border-2 border-dashed bg-white p-4 text-center" style="border-color: {{ $color }}">
            <p class="text-sm text-[#5E6672]">Save with code</p>
            <p class="mt-1 font-mono text-2xl font-bold tracking-widest" style="color: {{ $color }}">{{ $coupon->code }}</p>
            <p class="text-sm font-semibold">{{ $coupon->type === 'percentage' ? rtrim(rtrim(number_format((float) $coupon->value, 2), '0'), '.').'% off' : '$'.number_format((float) $coupon->value, 2).' off' }}@if ($coupon->expires_at) · ends {{ $coupon->expires_at->format('M j') }}@endif</p>
        </div>
    @endif

    @if ($featured->isNotEmpty())
        <h2 class="mt-7 text-xs font-bold uppercase tracking-wide text-[#5E6672]">Featured</h2>
        <div class="mt-2 grid grid-cols-2 gap-3">
            @foreach ($featured as $p)
                <a href="{{ $link($p) }}" class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-black/5 transition hover:-translate-y-0.5">
                    <img src="{{ $p->getFirstMediaUrl('images', 'thumb') ?: $p->getFirstMediaUrl('images') }}" alt="" class="aspect-square w-full object-cover" loading="lazy">
                    <div class="p-2.5">
                        <p class="line-clamp-2 text-xs font-semibold leading-snug">{{ $p->title }}</p>
                        <p class="mt-1 text-sm font-bold">${{ number_format($p->effectivePrice(), 2) }}
                            @if ($p->effectivePrice() < (float) $p->price)<span class="text-xs font-normal text-[#8A919C] line-through">${{ number_format((float) $p->price, 2) }}</span>@endif
                        </p>
                    </div>
                </a>
            @endforeach
        </div>
    @endif

    @if ($deals->isNotEmpty())
        <h2 class="mt-7 text-xs font-bold uppercase tracking-wide text-[#5E6672]">Deals</h2>
        <div class="mt-2 space-y-2">
            @foreach ($deals as $p)
                <a href="{{ $link($p) }}" class="flex items-center gap-3 rounded-2xl bg-white p-2.5 shadow-sm ring-1 ring-black/5">
                    <img src="{{ $p->getFirstMediaUrl('images', 'thumb') ?: $p->getFirstMediaUrl('images') }}" alt="" class="h-14 w-14 rounded-xl object-cover" loading="lazy">
                    <span class="min-w-0 flex-1"><span class="line-clamp-1 text-sm font-semibold">{{ $p->title }}</span>
                        <span class="text-sm font-bold" style="color: {{ $color }}">${{ number_format($p->effectivePrice(), 2) }}</span>
                        <span class="text-xs text-[#8A919C] line-through">${{ number_format((float) $p->price, 2) }}</span></span>
                    <span class="text-[#8A919C]">→</span>
                </a>
            @endforeach
        </div>
    @endif

    <footer class="mt-10 text-center text-xs text-[#8A919C]">
        <p>Payment held until delivery · Panmotive Fit Guarantee</p>
        <a href="{{ config('app.url') }}" class="mt-2 inline-flex items-center gap-1.5 font-semibold text-[#3D444E]"><x-ui.logo class="h-4 w-auto" /></a>
    </footer>
</main>
</body>
</html>
