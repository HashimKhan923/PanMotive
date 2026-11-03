@php
    $cap = config('marketplace.offsite_ads.fee_cap');
    $days = config('marketplace.offsite_ads.attribution_days');
    $threshold = config('marketplace.offsite_ads.large_seller_threshold');
    $rateLabel = rtrim(rtrim(number_format($rate, 2), '0'), '.');
@endphp
<div class="max-w-5xl">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">Offsite Ads</h1>
            <p class="mt-1 text-sm text-muted">We advertise your parts on Facebook, Instagram, Google and more — at our cost. You only pay when an ad leads to a sale.</p>
        </div>
        <span class="pill {{ $participating ? '!bg-emerald-500/10 !text-emerald-600' : '' }}">
            {{ $participating ? 'Your listings are being advertised' : 'Not advertising' }}
        </span>
    </div>

    @unless ($enabled)
        <div class="mt-4 rounded-xl border border-line bg-surface-2 px-4 py-3 text-sm text-muted">Offsite Ads is currently paused across the marketplace.</div>
    @endunless

    <div class="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="card p-4"><p class="text-xs uppercase tracking-wide text-muted">Ad clicks (30 days)</p><p class="mt-1 text-2xl font-bold">{{ number_format($clicks30) }}</p>
            <p class="text-xs text-muted">@foreach ($clicksByChannel as $ch => $n){{ $channels[$ch] ?? $ch }} {{ $n }}{{ $loop->last ? '' : ' · ' }}@endforeach</p></div>
        <div class="card p-4"><p class="text-xs uppercase tracking-wide text-muted">Orders from ads</p><p class="mt-1 text-2xl font-bold">{{ number_format($orders) }}</p><p class="text-xs text-muted">all-time</p></div>
        <div class="card p-4"><p class="text-xs uppercase tracking-wide text-muted">Sales from ads</p><p class="mt-1 text-2xl font-bold">${{ number_format($sales, 2) }}</p><p class="text-xs text-muted">all-time</p></div>
        <div class="card p-4"><p class="text-xs uppercase tracking-wide text-muted">Ad fees paid</p><p class="mt-1 text-2xl font-bold">${{ number_format($fees, 2) }}</p>
            <p class="text-xs text-muted">{{ $fees > 0 ? '$'.number_format($sales / $fees, 2).' in sales per $1' : 'only charged on sales' }}</p></div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-[1fr_320px]">
        <section class="card p-6">
            <h2 class="font-bold">How it works</h2>
            <ol class="mt-3 space-y-3 text-sm">
                <li class="flex gap-3"><span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-accent/15 text-xs font-bold text-accent">1</span>
                    <span>We show your listings in ads on Facebook, Instagram, Google, Pinterest and Bing — <strong>we pay for the ads</strong>.</span></li>
                <li class="flex gap-3"><span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-accent/15 text-xs font-bold text-accent">2</span>
                    <span>A shopper clicks an ad for one of your parts and lands on Pan Motive.</span></li>
                <li class="flex gap-3"><span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-accent/15 text-xs font-bold text-accent">3</span>
                    <span>If they buy from your store within <strong>{{ $days }} days</strong>, you pay a <strong>{{ $rateLabel }}% ad fee</strong> on that order (never more than <strong>${{ number_format($cap) }}</strong>). It's taken from that order's payout.</span></li>
                <li class="flex gap-3"><span class="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-accent/15 text-xs font-bold text-accent">4</span>
                    <span>No sale, no fee. Clicks and views are free for you. If the order is cancelled or refunded, the fee goes with it.</span></li>
            </ol>
            <p class="mt-4 rounded-lg bg-surface-2 px-3 py-2 text-xs text-muted">
                Example: a $200 order from an ad → {{ $rateLabel }}% = ${{ number_format(min($cap, 200 * $rate / 100), 2) }} ad fee, on top of the normal platform commission.
                Stores with over ${{ number_format($threshold) }} in yearly sales pay a lower {{ rtrim(rtrim(number_format(config('marketplace.offsite_ads.large_seller_fee_pct'), 2), '0'), '.') }}% and stay enrolled.
            </p>
        </section>

        <aside class="card h-fit p-6">
            <h2 class="font-bold">Your setting</h2>
            @if ($canOptOut)
                <p class="mt-2 text-sm text-muted">{{ $optedOut ? "You've opted out — your listings aren't in our ads and you pay no ad fees." : "You're in. Turn this off any time; orders from ads clicked before you opt out can still carry a fee." }}</p>
                <button wire:click="toggle" wire:confirm="{{ $optedOut ? 'Start advertising your listings with Offsite Ads?' : 'Stop advertising your listings with Offsite Ads?' }}"
                        class="mt-4 w-full {{ $optedOut ? 'btn-accent' : 'btn-outline' }} btn-sm">
                    {{ $optedOut ? 'Turn on Offsite Ads' : 'Opt out of Offsite Ads' }}
                </button>
            @else
                <p class="mt-2 text-sm text-muted">Your store is over ${{ number_format($threshold) }} in yearly sales, so Offsite Ads is always on — at the lower {{ $rateLabel }}% rate.</p>
            @endif
        </aside>
    </div>

    <section class="mt-6">
        <h2 class="font-bold">Orders from ads</h2>
        <div class="mt-3 overflow-x-auto rounded-xl border border-line">
            <table class="w-full min-w-[620px] text-sm">
                <thead class="bg-surface-2 text-left text-xs uppercase text-muted">
                    <tr><th class="px-4 py-3">Order</th><th class="px-4 py-3">Ad on</th><th class="px-4 py-3">Clicked</th><th class="px-4 py-3 text-right">Order value</th><th class="px-4 py-3 text-right">Ad fee</th></tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($history as $a)
                        <tr class="bg-surface" wire:key="oa-{{ $a->id }}">
                            <td class="px-4 py-3">
                                @php($line = \App\Models\OrderItem::where('order_id', $a->order_id)->where('seller_id', auth()->id())->first())
                                @if ($line)<a href="{{ route('seller.orders.show', $line) }}" class="font-medium hover:text-accent">{{ $a->order?->order_number }}</a>@else {{ $a->order?->order_number }} @endif
                                <p class="text-xs text-muted">{{ $a->created_at->format('M j, Y') }}</p>
                            </td>
                            <td class="px-4 py-3">{{ $channels[$a->channel] ?? $a->channel }}</td>
                            <td class="px-4 py-3 text-muted">{{ $a->clicked_at->diffForHumans($a->created_at, true) }} before ordering</td>
                            <td class="px-4 py-3 text-right">${{ number_format($a->attributed_sales, 2) }}</td>
                            <td class="px-4 py-3 text-right font-semibold">${{ number_format($a->fee_amount, 2) }} <span class="text-xs font-normal text-muted">({{ rtrim(rtrim(number_format($a->fee_rate, 2), '0'), '.') }}%)</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-12 text-center text-sm text-muted">No orders from ads yet. They'll appear here as shoppers buy after clicking our ads.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $history->links() }}</div>
    </section>
</div>
