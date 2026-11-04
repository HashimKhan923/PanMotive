<div class="max-w-5xl">
    <div class="flex flex-wrap items-center gap-3">
        <a href="{{ route('seller.customers.index') }}" class="btn-ghost !p-2" aria-label="Back to customers"><x-ui.icon name="arrow-left" class="w-6 h-6" /></a>
        <span class="grid h-12 w-12 place-items-center rounded-full bg-surface-2 text-lg font-bold text-fg">{{ strtoupper(mb_substr($buyer->name, 0, 1)) }}</span>
        <div>
            <h1 class="text-2xl font-bold">{{ $buyer->name }}</h1>
            <p class="text-sm text-muted">
                {{ $location ? $location.' · ' : '' }}Customer since {{ \Illuminate\Support\Carbon::parse($stats->first_order_at)->format('M Y') }}
                @if ($follows) · <span class="text-accent">Follows your store</span>@endif
            </p>
        </div>
        <div class="ml-auto flex flex-wrap items-center gap-2">
            @foreach ($badges as $b)
                <span class="pill {{ $b === 'VIP' ? '!bg-accent !text-accent-fg' : '' }}">{{ $b }}</span>
            @endforeach
            @if (\App\Support\SellerContext::can('messages'))
                <button wire:click="message" class="btn-accent btn-sm"><x-ui.icon name="message" class="w-4 h-4" /> Message</button>
            @endif
        </div>
    </div>

    <div class="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="card p-4"><p class="text-xs uppercase tracking-wide text-muted">Lifetime spend</p><p class="mt-1 text-2xl font-bold">${{ number_format((float) $stats->spent, 2) }}</p></div>
        <div class="card p-4"><p class="text-xs uppercase tracking-wide text-muted">Orders</p><p class="mt-1 text-2xl font-bold">{{ $stats->orders_count }}</p><p class="text-xs text-muted">{{ $stats->units }} {{ Str::plural('unit', (int) $stats->units) }}</p></div>
        <div class="card p-4"><p class="text-xs uppercase tracking-wide text-muted">Avg order</p><p class="mt-1 text-2xl font-bold">${{ number_format((float) $stats->spent / max(1, (int) $stats->orders_count), 2) }}</p></div>
        <div class="card p-4"><p class="text-xs uppercase tracking-wide text-muted">Last order</p><p class="mt-1 text-2xl font-bold">{{ \Illuminate\Support\Carbon::parse($stats->last_order_at)->diffForHumans(null, true) }}</p><p class="text-xs text-muted">ago</p></div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-[1fr_320px]">
        <div class="space-y-6">
            {{-- order history --}}
            <section class="card p-6">
                <h2 class="font-bold">Order history</h2>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[520px] text-sm">
                        <thead class="text-left text-xs uppercase text-muted">
                            <tr><th class="py-2">Order</th><th class="py-2">Item</th><th class="py-2 text-right">Total</th><th class="py-2 text-right">Status</th></tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($items as $i)
                                <tr>
                                    <td class="py-2"><a href="{{ route('seller.orders.show', $i) }}" class="font-medium hover:text-accent">{{ $i->order->order_number }}</a><p class="text-xs text-muted">{{ $i->created_at->format('M j, Y') }}</p></td>
                                    <td class="py-2">{{ $i->product_title }} <span class="text-muted">× {{ $i->quantity }}</span></td>
                                    <td class="py-2 text-right">${{ number_format($i->line_total, 2) }}</td>
                                    <td class="py-2 text-right"><span class="pill">{{ $i->status->label() }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            @if ($topProducts->isNotEmpty())
                <section class="card p-6">
                    <h2 class="font-bold">What they buy</h2>
                    <ul class="mt-3 divide-y divide-line text-sm">
                        @foreach ($topProducts as $p)
                            <li class="flex items-center justify-between gap-3 py-2">
                                <span>{{ $p['title'] }} <span class="text-muted">· {{ $p['units'] }} {{ Str::plural('unit', $p['units']) }}</span></span>
                                <span class="font-semibold">${{ number_format($p['spent'], 2) }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($reviews->isNotEmpty())
                <section class="card p-6">
                    <h2 class="font-bold">Reviews they left</h2>
                    <div class="mt-3 space-y-3">
                        @foreach ($reviews as $r)
                            <div class="rounded-lg border border-line p-3 text-sm">
                                <div class="flex items-center justify-between gap-2">
                                    <x-ui.rating :value="$r->rating" />
                                    <span class="text-xs text-muted">{{ $r->created_at->format('M j, Y') }}</span>
                                </div>
                                <p class="mt-1 font-medium">{{ $r->title ?: $r->product?->title }}</p>
                                @if ($r->body)<p class="mt-0.5 text-muted">{{ Str::limit($r->body, 220) }}</p>@endif
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            @if ($returns->isNotEmpty() || $questions->isNotEmpty())
                <section class="card p-6">
                    <h2 class="font-bold">Returns &amp; questions</h2>
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach ($returns as $rma)
                            <li class="flex items-center justify-between gap-2"><span>Return {{ $rma->rma_number }} — {{ $rma->reason }}</span><span class="pill capitalize">{{ $rma->status }}</span></li>
                        @endforeach
                        @foreach ($questions as $q)
                            <li class="flex items-center justify-between gap-2"><span>Asked: “{{ Str::limit($q->body, 70) }}” <span class="text-muted">on {{ $q->product?->title }}</span></span>
                                <a href="{{ route('seller.questions.index') }}" class="text-xs font-medium text-fg underline underline-offset-2">Q&amp;A</a></li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>

        <aside class="space-y-6">
            <section class="card p-6">
                <h2 class="font-bold">Private notes</h2>
                <p class="mt-1 text-xs text-muted">Only you and your team can see these.</p>
                <div class="mt-3 space-y-3">
                    <textarea wire:model="notes" rows="5" maxlength="5000" class="input text-sm" placeholder="e.g. Prefers UPS. Runs a tuning shop — asks about bulk pricing." aria-label="Notes"></textarea>
                    <div>
                        <label class="label" for="cp-tags">Tags</label>
                        <input id="cp-tags" wire:model="tagsInput" class="input h-10 text-sm" placeholder="wholesale, shop, track-day">
                        <p class="mt-1 text-xs text-muted">Comma-separated. Filter your customer list by tag.</p>
                    </div>
                    @error('notes') <p class="text-xs text-rose-500">{{ $message }}</p> @enderror
                    <button wire:click="saveNotes" class="btn-accent btn-sm w-full">Save notes</button>
                </div>
            </section>

            @if (\App\Support\SellerContext::can('marketing') && ! $blocked)
                <section class="card p-6" x-data="{ open: false }">
                    <h2 class="font-bold">Send a private coupon</h2>
                    <p class="mt-1 text-xs text-muted">A one-time code only {{ \Illuminate\Support\Str::before($buyer->name, ' ') }} can use on your items, delivered to their Panmotive inbox. Great for thank-yous and win-backs.</p>
                    <button x-show="!open" @click="open = true" class="btn-ghost btn-sm mt-3 w-full"><x-ui.icon name="tag" class="w-4 h-4" /> Create coupon</button>
                    <form x-show="open" x-cloak wire:submit="sendCoupon" class="mt-3 space-y-3">
                        <div class="grid grid-cols-[1fr_96px] gap-2">
                            <select wire:model.live="couponType" class="input h-10 text-sm" aria-label="Discount type">
                                <option value="percentage">% off</option>
                                <option value="fixed">$ off</option>
                            </select>
                            <input wire:model="couponValue" type="number" step="0.01" min="1" class="input h-10 text-sm" aria-label="Discount amount">
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="label text-xs" for="cp-days">Valid for</label>
                                <select id="cp-days" wire:model="couponDays" class="input h-10 text-sm">
                                    @foreach ([7, 14, 30, 60, 90] as $d)<option value="{{ $d }}">{{ $d }} days</option>@endforeach
                                </select>
                            </div>
                            <div>
                                <label class="label text-xs" for="cp-min">Min. order ($)</label>
                                <input id="cp-min" wire:model="couponMinOrder" type="number" step="0.01" min="0" class="input h-10 text-sm" placeholder="None">
                            </div>
                        </div>
                        <textarea wire:model="couponMessage" rows="2" maxlength="500" class="input text-sm" placeholder="Personal note (optional)"></textarea>
                        @foreach (['couponValue', 'couponDays', 'couponMinOrder', 'couponMessage'] as $f)
                            @error($f) <p class="text-xs text-rose-500">{{ $message }}</p> @enderror
                        @endforeach
                        <button class="btn-accent btn-sm w-full" wire:loading.attr="disabled" wire:target="sendCoupon">Send coupon</button>
                    </form>

                    @if ($coupons->isNotEmpty())
                        <ul class="mt-4 space-y-1.5 border-t border-line pt-3 text-xs">
                            @foreach ($coupons as $c)
                                <li class="flex items-center justify-between gap-2">
                                    <span class="font-mono">{{ $c->code }}</span>
                                    <span class="text-muted">
                                        {{ $c->type === 'percentage' ? rtrim(rtrim(number_format((float) $c->value, 2), '0'), '.').'%' : '$'.number_format((float) $c->value, 2) }}
                                        ·
                                        @if ($c->times_used > 0) <span class="text-emerald-600">used</span>
                                        @elseif ($c->expires_at?->isPast()) expired
                                        @else until {{ $c->expires_at?->format('M j') }} @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endif

            @if ($loyalty)
                <section class="card p-6">
                    <h2 class="font-bold">Loyalty</h2>
                    <p class="mt-2 text-2xl font-bold">{{ number_format($loyalty->points) }} <span class="text-sm font-normal text-muted">points</span></p>
                    <p class="text-xs text-muted">{{ number_format($loyalty->lifetime_points) }} earned all-time</p>
                </section>
            @endif

            @if ($subscriptions->isNotEmpty())
                <section class="card p-6">
                    <h2 class="font-bold">Subscribe &amp; Save</h2>
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach ($subscriptions as $s)
                            <li>
                                <p class="font-medium">{{ $s->product?->title }}</p>
                                <p class="text-xs text-muted">{{ $s->quantity }} × {{ strtolower($s->intervalLabel()) }} · <span class="capitalize">{{ $s->status }}</span>@if ($s->status === 'active' && $s->next_run_at) · next {{ $s->next_run_at->format('M j') }}@endif</p>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if (\App\Support\SellerContext::can('messages'))
                <section class="card p-6" x-data="{ confirm: false }">
                    @if ($blocked)
                        <h2 class="font-bold text-rose-500">Blocked</h2>
                        <p class="mt-1 text-xs text-muted">Since {{ $blocked->created_at->format('M j, Y') }}{{ $blocked->reason ? ' — '.$blocked->reason : '' }}. They can't message you, make offers, ask on your listings or buy your items.</p>
                        <button wire:click="toggleBlock" class="btn-ghost btn-sm mt-3 w-full">Unblock</button>
                    @else
                        <button @click="confirm = !confirm" class="text-xs text-muted hover:text-rose-500">Block this buyer…</button>
                        <div x-show="confirm" x-cloak class="mt-2 space-y-2">
                            <p class="text-[11px] text-muted">For abusive buyers or repeated bad-faith returns. Blocks messages, offers, questions and new purchases from your store. Existing orders are not affected.</p>
                            <input wire:model="blockReason" class="input h-9 text-xs" placeholder="Reason (private)" maxlength="255">
                            <button wire:click="toggleBlock" class="w-full rounded-lg bg-rose-500 px-3 py-1.5 text-xs font-semibold text-white">Block buyer</button>
                        </div>
                    @endif
                </section>
            @endif
        </aside>
    </div>
</div>
