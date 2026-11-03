@extends('layouts.admin')

@section('title', 'Offsite Ads')

@section('content')
<x-admin.header title="Offsite Ads"
    :subtitle="'We run ads for sellers on our own accounts. Sellers pay '.config('marketplace.offsite_ads.fee_pct').'% ('.config('marketplace.offsite_ads.large_seller_fee_pct').'% for stores over $'.number_format(config('marketplace.offsite_ads.large_seller_threshold')).' a year), up to $'.number_format(config('marketplace.offsite_ads.fee_cap')).', on orders within '.config('marketplace.offsite_ads.attribution_days').' days of a click.'">
    <x-admin.status :value="$enabled ? 'on' : 'off'" :label="$enabled ? 'Program on' : 'Program off'" />
</x-admin.header>

<div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3" data-enter>
    <x-admin.stat label="Fees earned, 6 months" :value="$totals['fees']" prefix="$" :decimals="2" icon="dollar" />
    <x-admin.stat label="Our ad spend, 6 months" :value="$totals['spend']" prefix="$" :decimals="2" icon="external" />
    <x-admin.stat label="Profit, 6 months" :value="$totals['fees'] - $totals['spend']" prefix="$" :decimals="2" icon="chart" :hint="'$'.number_format($totals['sales'], 2).' seller sales driven'" />
</div>

<x-admin.card title="Month by month" flush class="mb-4">
    <table class="adm-table">
        <thead><tr><th>Month</th><th class="num">Clicks</th><th class="num">Orders</th><th class="num">Seller sales</th><th class="num">Fees earned</th><th class="num">Our spend</th><th class="num">Profit</th></tr></thead>
        <tbody>
            @foreach ($months as $m)
                <tr>
                    <td>{{ $m['label'] }}</td><td class="num">{{ number_format($m['clicks']) }}</td><td class="num">{{ number_format($m['orders']) }}</td>
                    <td class="num">${{ number_format($m['sales'], 2) }}</td><td class="num">${{ number_format($m['fees'], 2) }}</td><td class="num">${{ number_format($m['spend'], 2) }}</td>
                    <td class="num font-semibold">{{ $m['profit'] < 0 ? '−' : '' }}${{ number_format(abs($m['profit']), 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</x-admin.card>

<div class="grid gap-4 lg:grid-cols-2">
    <x-admin.card title="Product feeds" subtitle="Paste a URL into Meta Commerce Manager, Google Merchant Center, Pinterest Catalogs or Microsoft Merchant Center. Refresh daily.">
        <ul class="space-y-3">
            @foreach ($feeds as $code => $url)
                <li>
                    <label class="label" for="feed-{{ $code }}">{{ $channels[$code] }}</label>
                    <input id="feed-{{ $code }}" readonly value="{{ $url }}" x-data @click="$el.select()" class="input font-mono text-xs">
                </li>
            @endforeach
        </ul>
    </x-admin.card>

    <div class="space-y-4">
        <x-admin.card title="Record our ad spend" subtitle="Copy each month's total from the platform's billing page. Saving a month again replaces it.">
            <form method="POST" action="{{ route('admin.offsite-ads.spend') }}" class="space-y-3">
                @csrf
                <div class="grid grid-cols-2 gap-2">
                    <div><label class="label" for="s-month">Month</label><input id="s-month" type="month" name="month" value="{{ now()->format('Y-m') }}" class="input text-sm" required></div>
                    <div><label class="label" for="s-ch">Platform</label><select id="s-ch" name="channel" class="input text-sm">@foreach ($channels as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select></div>
                </div>
                <div><label class="label" for="s-amt">Amount ($)</label><input id="s-amt" type="number" step="0.01" min="0" name="amount" class="input text-sm" required></div>
                <div><label class="label" for="s-note">Note</label><input id="s-note" name="note" maxlength="255" class="input text-sm"></div>
                <button class="btn-accent btn-sm">Save spend</button>
            </form>
            @if ($spendRows->isNotEmpty())
                <ul class="mt-4 divide-y divide-line/60 border-t border-line/60 text-[13px]">
                    @foreach ($spendRows as $s)
                        <li class="flex justify-between py-2"><span>{{ $s->month->format('M Y') }} · {{ $channels[$s->channel] ?? $s->channel }}</span><span class="font-medium tabular-nums">${{ number_format($s->amount, 2) }}</span></li>
                    @endforeach
                </ul>
            @endif
        </x-admin.card>

        <x-admin.card title="Top sellers by ad fees" flush>
            @if ($topSellers->isEmpty())
                <x-admin.empty icon="external" title="No ad-driven orders yet" class="!py-8" />
            @else
                <table class="adm-table">
                    <thead><tr><th>Store</th><th class="num">Orders</th><th class="num">Sales</th><th class="num">Fees</th></tr></thead>
                    <tbody>
                        @foreach ($topSellers as $t)
                            <tr><td>{{ $t->seller?->sellerProfile?->business_name ?? $t->seller?->name }}</td><td class="num">{{ number_format($t->orders) }}</td><td class="num">${{ number_format($t->sales, 2) }}</td><td class="num">${{ number_format($t->fees, 2) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-admin.card>
    </div>
</div>
@endsection
