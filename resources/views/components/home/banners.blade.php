@props(['banners'])
{{-- Admin-managed homepage banners (Admin → Content → Homepage banners). Several in one spot play
     as a slider. All motion lives in resources/js/banners.js and stops under reduced motion. Without
     JavaScript the first banner simply shows, fully readable. --}}
@php
    $banners = collect($banners)->values();
    $many = $banners->count() > 1;
@endphp
@if ($banners->isNotEmpty())
<section class="container-x py-10">
    <div data-banner-rail data-autoplay="7"
         class="pm-banner-rail relative isolate overflow-hidden rounded-3xl"
         role="region" aria-label="Promotions" @if ($many) aria-roledescription="carousel" @endif>

        <div class="pm-banner-stage">
            @foreach ($banners as $i => $b)
                @php
                    $style = array_key_exists($b->style, \App\Models\Banner::STYLES) ? $b->style : 'charcoal';
                    $onDark = $style !== 'light';
                    $image = $b->imageUrl();
                    $primary = match ($style) {
                        'red' => 'btn bg-white text-[#15191E] hover:bg-white/90 shadow-[0_10px_30px_-10px_rgb(0_0_0/.45)]',
                        default => 'btn-accent',
                    };
                    $secondary = $onDark ? 'btn border border-white/30 text-white hover:bg-white/10' : 'btn-outline';
                    $external = fn ($url) => str_starts_with((string) $url, 'http') ? 'rel=noopener' : '';
                @endphp
                <article data-banner-slide
                         class="pm-banner pm-banner--{{ $style }} relative isolate overflow-hidden rounded-3xl {{ $i === 0 ? 'is-active' : '' }}
                                {{ match ($style) { 'charcoal' => 'bg-brand text-brand-fg', 'red' => 'text-white', default => 'bg-surface-2 text-fg ring-1 ring-inset ring-line' } }}"
                         @if ($many) role="group" aria-roledescription="slide" aria-label="{{ $i + 1 }} of {{ $banners->count() }}" @endif
                         @if ($i > 0) inert aria-hidden="true" @endif>

                    @if ($style === 'charcoal')
                        <div class="pm-aurora -z-10 opacity-60" aria-hidden="true"><span></span><span></span><span></span></div>
                    @endif
                    <div class="pm-banner-glow" aria-hidden="true"></div>
                    <div class="pm-grain pointer-events-none absolute inset-0 -z-10" aria-hidden="true"></div>

                    <div class="relative grid min-h-[340px] md:grid-cols-[1.1fr_1fr] lg:min-h-[380px]">
                        {{-- words --}}
                        {{-- With slider controls, leave room under the buttons so the controls never cover them. --}}
                        <div class="relative z-10 flex flex-col justify-center px-7 py-9 sm:px-12 sm:py-12 lg:px-16 {{ $many ? 'pb-24 sm:pb-24 md:pb-12' : '' }}">
                            @if ($b->eyebrow)
                                <p data-banner-in class="inline-flex items-center gap-3 text-[11px] font-semibold uppercase tracking-[.2em] {{ $onDark ? 'text-white/80' : 'text-muted' }}">
                                    <span class="h-px w-8 {{ $style === 'light' ? 'bg-accent' : 'bg-current' }}" aria-hidden="true"></span>{{ $b->eyebrow }}
                                </p>
                            @endif
                            <h2 data-banner-title class="mt-4 max-w-xl {{ $onDark ? 'text-white' : 'text-fg' }} text-3xl font-bold leading-[1.06] tracking-tight sm:text-4xl lg:text-[3.1rem]">{{ $b->title }}</h2>
                            @if ($b->subtitle)
                                <p data-banner-in class="mt-4 max-w-lg text-base sm:text-lg {{ $onDark ? 'text-white/85' : 'text-muted' }}">{{ $b->subtitle }}</p>
                            @endif
                            @if ($b->button_url || $b->second_url)
                                <div data-banner-in class="mt-7 flex flex-wrap items-center gap-3">
                                    @if ($b->button_url)
                                        <a href="{{ $b->button_url }}" {{ $external($b->button_url) }} data-magnetic class="{{ $primary }} group relative">
                                            {{ $b->button_label }} <x-ui.icon name="arrow-right" class="w-4 h-4 transition-transform duration-300 group-hover:translate-x-1" />
                                        </a>
                                    @endif
                                    @if ($b->second_url)
                                        <a href="{{ $b->second_url }}" {{ $external($b->second_url) }} class="{{ $secondary }}">{{ $b->second_label }}</a>
                                    @endif
                                </div>
                            @endif
                        </div>

                        {{-- picture, or the brand mark when there's no photo --}}
                        <div class="relative order-first h-48 sm:h-64 md:order-none md:h-auto" aria-hidden="true">
                            @if ($image)
                                <div class="pm-banner-cut absolute inset-0">
                                    <div class="pm-banner-uncut absolute inset-0">
                                        <div data-banner-depth class="absolute inset-[-6%]">
                                            <div data-banner-media class="h-full w-full">
                                                <img src="{{ $image }}" alt="" loading="{{ $i === 0 ? 'eager' : 'lazy' }}" decoding="async"
                                                     class="pm-banner-kenburns h-full w-full object-cover">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <span class="pm-banner-edge {{ $style === 'red' ? 'bg-white' : 'bg-accent' }}"></span>
                            @else
                                <div class="pm-cut-field hidden md:block" @if ($style === 'red') style="background: rgb(255 255 255 / .12)" @endif></div>
                                <div data-banner-depth class="absolute inset-0 grid place-items-center">
                                    <div data-banner-deco class="pm-banner-float">
                                        <x-ui.logo variant="symbol" :on-red="$style === 'red'" class="h-24 w-auto drop-shadow-[0_20px_40px_rgb(0_0_0/.35)] md:h-32" />
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </article>
            @endforeach
        </div>

        {{-- the two-band forward cut that wipes between slides --}}
        <div class="pm-banner-sweep" aria-hidden="true"><span></span><span></span></div>

        @if ($many)
            <div class="absolute bottom-4 right-4 z-30 flex items-center gap-3 rounded-full bg-black/40 px-3 py-1.5 text-white backdrop-blur-md sm:bottom-6 sm:right-6">
                <button type="button" data-banner-prev class="pm-tap relative grid h-7 w-7 place-items-center rounded-full transition hover:bg-white/15" aria-label="Previous banner">
                    <x-ui.icon name="arrow-left" class="w-4 h-4" />
                </button>
                <div class="flex items-center gap-1.5">
                    @foreach ($banners as $i => $b)
                        <button type="button" data-banner-dot class="pm-tap relative h-1.5 w-7 overflow-hidden rounded-full bg-white/30 transition-[width] duration-500 aria-[current=true]:w-12"
                                aria-label="Show banner {{ $i + 1 }}" @if ($i === 0) aria-current="true" @endif>
                            <i data-fill class="absolute inset-0 origin-left bg-white {{ $i === 0 ? '' : 'scale-x-0' }}"></i>
                        </button>
                    @endforeach
                </div>
                <span data-banner-count class="min-w-[3.25rem] text-center text-[11px] font-semibold tabular-nums tracking-wider">01 / {{ str_pad($banners->count(), 2, '0', STR_PAD_LEFT) }}</span>
                <button type="button" data-banner-next class="pm-tap relative grid h-7 w-7 place-items-center rounded-full transition hover:bg-white/15" aria-label="Next banner">
                    <x-ui.icon name="arrow-right" class="w-4 h-4" />
                </button>
            </div>
        @endif
    </div>
</section>
@endif
