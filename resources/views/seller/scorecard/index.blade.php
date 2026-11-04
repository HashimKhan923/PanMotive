@extends('layouts.seller')

@section('title', 'Performance')

@section('content')
@php
    $tierOrder = array_keys($tiers);
    $tierIndex = array_search($m['tier'], $tierOrder, true);
    $tierTone = ['standard' => 'text-muted', 'rising' => 'text-sky-500', 'top_rated' => 'text-accent', 'powerseller' => 'text-amber-500'][$m['tier']] ?? 'text-muted';
@endphp
<div class="max-w-5xl">
    <h1 class="text-2xl font-bold">Performance &amp; tier</h1>
    <p class="mt-1 text-sm text-muted">
        Measured over the <strong>last {{ $m['window_days'] === 90 ? '90 days' : '12 months' }}</strong>{{ $m['window_days'] === 365 ? ' (stores with '.\App\Services\Seller\SellerScorecardService::BUSY_STORE_ORDERS.'+ orders in 90 days are measured on 90 days)' : '' }} — older problems drop off on their own.
        Hit every target to unlock the next tier's perks. Recalculated daily.
    </p>

    {{-- Tier banner --}}
    <div class="mt-6 overflow-hidden rounded-2xl bg-brand p-6 text-brand-fg sm:p-8" data-reveal="scale">
        <div class="flex flex-wrap items-center justify-between gap-6">
            <div>
                <p class="text-xs font-semibold uppercase tracking-widest text-white/50">Your tier</p>
                <p class="mt-1 flex items-center gap-2 text-3xl font-extrabold {{ str_replace('text-', 'text-', $tierTone) }}">
                    <x-ui.icon name="shield" class="w-7 h-7" /> {{ $m['tier_label'] }}
                </p>
                <p class="mt-1 text-sm text-white/60">
                    Commission rate: <span class="font-semibold text-white">{{ rtrim(rtrim(number_format(auth()->user()->sellerProfile->commissionRate(), 2), '0'), '.') }}%</span>
                    @if ($m['next_tier']) · next: {{ $m['next_tier_label'] }} @endif
                </p>
            </div>
            <div class="flex gap-2">
                @foreach ($tierOrder as $i => $key)
                    <div class="flex flex-col items-center gap-1">
                        <span class="grid h-9 w-9 place-items-center rounded-full text-xs font-bold
                                     {{ $i <= $tierIndex ? 'bg-accent text-accent-fg' : 'bg-white/10 text-white/50' }}">{{ $i + 1 }}</span>
                        <span class="text-[10px] {{ $i === $tierIndex ? 'text-white' : 'text-white/40' }}">{{ $tiers[$key]['label'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Metric rings --}}
    <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" data-reveal-group>
        <div class="card grid place-items-center p-6">
            <x-ui.ring :value="$m['on_time_ship_rate']" :display="$m['on_time_ship_rate']" tone="{{ $m['on_time_ship_rate'] >= 95 ? 'emerald' : ($m['on_time_ship_rate'] >= 90 ? 'amber' : 'rose') }}" label="On-time shipping" />
        </div>
        <div class="card grid place-items-center p-6">
            <x-ui.ring :value="max(0, 100 - $m['defect_rate'] * 6)" :display="$m['defect_rate']" tone="{{ $m['defect_rate'] <= 1.5 ? 'emerald' : ($m['defect_rate'] <= 3 ? 'amber' : 'rose') }}" label="Order defect rate" />
        </div>
        <div class="card grid place-items-center p-6">
            <x-ui.ring :value="$m['response_rate']" :display="$m['response_rate']" tone="{{ $m['response_rate'] >= 85 ? 'emerald' : ($m['response_rate'] >= 70 ? 'amber' : 'rose') }}" label="Message response" :sub="$m['avg_response_hours'] !== null ? 'avg '.$m['avg_response_hours'].'h to reply' : null" />
        </div>
        <div class="card grid place-items-center p-6">
            <x-ui.ring :value="min(100, $m['rating'] / 5 * 100)" :display="number_format($m['rating'], 1)" suffix="" tone="{{ $m['rating'] >= 4.5 ? 'emerald' : ($m['rating'] >= 4 ? 'amber' : 'rose') }}" label="Seller rating" :sub="$m['rating_count'].' ratings'" />
        </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-[1fr_320px]">
        {{-- Next tier --}}
        <div class="card p-6" data-reveal>
            @if ($m['next_tier'])
                <h2 class="font-bold">Unlock {{ $m['next_tier_label'] }}</h2>
                @if (empty($m['next_gaps']))
                    <p class="mt-2 rounded-lg bg-emerald-500/10 px-3 py-2 text-sm text-emerald-600">You meet every target — your tier updates automatically.</p>
                @else
                    <ul class="mt-4 space-y-2 text-sm">
                        @foreach ($m['next_gaps'] as $gap)
                            <li class="flex items-center justify-between rounded-lg border border-line px-3 py-2">
                                <span>{{ $gap['metric'] }}</span>
                                <span class="text-muted">{{ $gap['have'] }} <span class="mx-1">→</span> <span class="font-semibold text-fg">{{ $gap['need'] }}</span></span>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <div class="mt-4 rounded-xl bg-surface-2 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-muted">Perks at {{ $m['next_tier_label'] }}</p>
                    <ul class="mt-2 space-y-1 text-sm">
                        @foreach ($tiers[$m['next_tier']]['perks'] as $perk)
                            <li class="flex gap-2"><x-ui.icon name="check" class="mt-0.5 w-4 h-4 shrink-0 text-accent" /> {{ $perk }}</li>
                        @endforeach
                    </ul>
                </div>
            @else
                <h2 class="font-bold">You're at the top 🏆</h2>
                <p class="mt-2 text-sm text-muted">Powerseller is the highest tier. Keep your metrics green to hold it.</p>
            @endif
        </div>

        {{-- Current perks + volume --}}
        <div class="space-y-4" data-reveal>
            <div class="card p-6">
                <p class="text-xs font-semibold uppercase tracking-wide text-muted">Fulfilled orders</p>
                <p class="mt-1 text-3xl font-bold" data-counter-to="{{ $m['fulfilled_orders'] }}">{{ $m['fulfilled_orders'] }}</p>
                <p class="mt-1 text-xs text-muted">last 12 months · cancellation rate {{ $m['cancellation_rate'] }}%</p>
            </div>
            <details class="card p-6 text-sm">
                <summary class="cursor-pointer font-semibold">How these are measured</summary>
                <ul class="mt-3 space-y-2 text-xs text-muted">
                    <li><strong class="text-fg">On-time shipping</strong> — shipped by each listing's handling time (business days from payment), the same ship-by date shown on Orders.</li>
                    <li><strong class="text-fg">Order defects</strong> — cancellations you caused, returns for a wrong or damaged part, and buyer claims you didn't resolve yourself. Buyer-requested and unpaid cancellations, and "changed my mind" returns, don't count.</li>
                    <li><strong class="text-fg">Message response</strong> — buyer conversations answered; a thread only counts against you after {{ config('marketplace.messages.response_target_hours') }} hours without a reply.</li>
                </ul>
            </details>
            <div class="card p-6">
                <p class="text-xs font-semibold uppercase tracking-wide text-muted">Your perks now</p>
                <ul class="mt-2 space-y-1 text-sm">
                    @foreach (auth()->user()->sellerProfile->tierConfig()['perks'] as $perk)
                        <li class="flex gap-2"><x-ui.icon name="check" class="mt-0.5 w-4 h-4 shrink-0 text-accent" /> {{ $perk }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</div>
@endsection
