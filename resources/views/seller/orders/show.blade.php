@extends('layouts.seller')

@section('title', 'Order ' . $item->order->order_number)

@section('content')
@php
    $addr = $item->order->shipping_address ?? [];
@endphp
<div class="max-w-3xl">
    <div class="flex items-center gap-3">
        <a href="{{ route('seller.orders.index') }}" class="btn-ghost !p-2" aria-label="Back"><x-ui.icon name="arrow-left" class="w-6 h-6" /></a>
        <h1 class="text-2xl font-bold">{{ $item->order->order_number }}</h1>
        <span class="pill">{{ $item->status->label() }}</span>
        <div class="ml-auto flex gap-2">
            <a href="{{ route('seller.orders.invoice', $item->order) }}" target="_blank" class="btn-outline btn-sm">
                <x-ui.icon name="printer" class="w-4 h-4" /> Invoice
            </a>
            <a href="{{ route('seller.fulfilment.packing-slip', $item->order) }}" target="_blank" class="btn-outline btn-sm">
                <x-ui.icon name="list" class="w-4 h-4" /> Packing slip
            </a>
        </div>
    </div>

    @if ($errors->has('cancel') || $errors->has('advance'))
        <div class="mt-4 rounded-xl border border-rose-500/40 bg-rose-500/10 px-4 py-3 text-sm text-rose-700 dark:text-rose-400">{{ $errors->first('cancel') ?: $errors->first('advance') }}</div>
    @endif

    <div class="mt-6 grid gap-6 lg:grid-cols-[1fr_280px]">
        <div class="space-y-6">
            {{-- line item --}}
            <div class="card p-6">
                <h2 class="font-bold">Item</h2>
                <div class="mt-3 flex items-start justify-between gap-4">
                    <x-order-thumb :item="$item" size="h-20 w-20" class="rounded-xl" />
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ $item->product_title }}</p>
                        @if ($item->variant_label)<p class="mt-0.5 text-sm">{{ $item->variant_label }}</p>@endif
                        <p class="mt-0.5 text-sm text-muted">SKU {{ $item->product_sku ?: '—' }} · Qty {{ $item->quantity }} × ${{ number_format($item->unit_price, 2) }}</p>
                    </div>
                    <p class="text-right font-semibold">${{ number_format($item->line_total, 2) }}</p>
                </div>
                <dl class="mt-4 space-y-1.5 border-t border-line pt-4 text-sm">
                    <div class="flex justify-between"><dt class="text-muted">Item total</dt><dd>${{ number_format($item->line_total, 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">Platform fee ({{ rtrim(rtrim(number_format($item->commission_rate, 1), '0'), '.') }}%)</dt><dd>−${{ number_format($item->platform_fee_amount, 2) }}</dd></div>
                    @if ((float) $item->offsite_ad_fee > 0)
                        <div class="flex justify-between">
                            <dt class="text-muted">Offsite Ads fee <a href="{{ route('seller.offsite-ads.index') }}" class="text-xs text-fg underline underline-offset-2">(buyer came from our ad)</a></dt>
                            <dd>−${{ number_format($item->offsite_ad_fee, 2) }}</dd>
                        </div>
                    @endif
                    <div class="flex justify-between border-t border-line pt-1.5 font-semibold"><dt>Your payout</dt><dd>${{ number_format($item->seller_payout_amount, 2) }}</dd></div>
                </dl>
            </div>

            {{-- actions --}}
            @if ($item->status->value === 'placed')
                <div class="card flex items-start gap-3 p-6">
                    <x-ui.icon name="clock" class="mt-0.5 w-6 h-6 shrink-0 text-muted" />
                    <div>
                        <p class="font-semibold">Awaiting payment</p>
                        <p class="text-sm text-muted">The buyer hasn't completed payment yet. You'll get a notification to accept as soon as it clears — don't ship anything before then.</p>
                    </div>
                </div>
            @elseif ($item->status->value === 'paid')
                <form method="POST" action="{{ route('seller.orders.accept', $item) }}" class="card flex items-center justify-between gap-4 p-6">
                    @csrf
                    <div>
                        <p class="font-semibold">New order — paid</p>
                        <p class="text-sm text-muted">Accept it to start fulfilment, or cancel if you can't fill it.</p>
                        @if ($shipBy = $item->shipBy())
                            <p class="mt-1 text-xs font-semibold {{ $item->isLate() ? 'text-rose-600' : 'text-muted' }}">{{ $item->isLate() ? 'Late — should have shipped by '.$shipBy->format('D, M j') : 'Ship by '.$shipBy->format('D, M j') }}</p>
                        @endif
                    </div>
                    <button class="btn-accent btn-sm">Accept order</button>
                </form>
            @elseif ($item->status->value === 'seller_accepted')
                @php($siblingAccepted = \App\Models\OrderItem::where('order_id', $item->order_id)->where('seller_id', auth()->id())->where('status', 'seller_accepted')->count())
                @if (\App\Services\Fulfilment\LabelService::available())
                    <div class="card flex flex-wrap items-center justify-between gap-3 p-6">
                        <div>
                            <p class="font-bold">Buy a discounted label</p>
                            <p class="text-xs text-muted">Prints tracking, marks shipped{{ $siblingAccepted > 1 ? ', covers all '.$siblingAccepted.' of your items in this order' : '' }}. Postage comes out of this order's payout.</p>
                            @if ($shipBy = $item->shipBy())
                                <p class="mt-1 text-xs font-semibold {{ $item->isLate() ? 'text-rose-600' : 'text-amber-600' }}">{{ $item->isLate() ? 'Late — should have shipped by '.$shipBy->format('D, M j') : 'Ship by '.$shipBy->format('D, M j') }}</p>
                            @endif
                        </div>
                        <a href="{{ route('seller.fulfilment.label', $item->order) }}" class="btn-accent btn-sm">Get rates</a>
                    </div>
                @elseif ($shipBy = $item->shipBy())
                    <p class="text-xs font-semibold {{ $item->isLate() ? 'text-rose-600' : 'text-amber-600' }}">{{ $item->isLate() ? 'Late — should have shipped by '.$shipBy->format('D, M j') : 'Ship by '.$shipBy->format('D, M j') }}</p>
                @endif

                <form method="POST" action="{{ route('seller.orders.ship', $item) }}" class="card space-y-4 p-6">
                    @csrf
                    <h2 class="font-bold">{{ \App\Services\Fulfilment\LabelService::available() ? 'Or enter tracking manually' : 'Add tracking' }}</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="label" for="carrier">Carrier</label>
                            <select id="carrier" name="carrier" class="input" required>
                                <option value="USPS">USPS</option>
                                <option value="UPS">UPS</option>
                                <option value="FedEx">FedEx</option>
                                <option value="DHL">DHL</option>
                            </select>
                            @error('carrier') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label" for="tracking_number">Tracking number</label>
                            <input id="tracking_number" name="tracking_number" class="input" required>
                            @error('tracking_number') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    @if ($siblingAccepted > 1)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="ship_all" value="1" class="rounded border-line text-accent focus:ring-accent">
                            Apply to all {{ $siblingAccepted }} of my items in this order
                        </label>
                    @endif
                    <button class="btn-accent btn-sm">Confirm shipment</button>
                </form>
            @elseif ($item->tracking_number)
                <div class="card p-6">
                    <h2 class="font-bold">Shipment</h2>
                    <p class="mt-2 text-sm">{{ $item->carrier }} · <span class="font-mono">{{ $item->tracking_number }}</span>
                        @if ($item->trackingUrl())<a href="{{ $item->trackingUrl() }}" target="_blank" rel="noopener" class="ml-2 text-xs font-medium text-accent hover:underline">Track package ↗</a>@endif</p>
                    <p class="mt-1 text-xs text-muted">Shipped {{ optional($item->shipped_at)->format('M j, Y') }}{{ (float) $item->label_fee > 0 ? ' · $'.number_format($item->label_fee, 2).' postage' : '' }}</p>
                    @php($label = \App\Models\ShippingLabel::whereHas('items', fn ($q) => $q->where('order_items.id', $item->id))->whereNotNull('label_url')->latest()->first())
                    @if ($label)
                        <a href="{{ $label->label_url }}" target="_blank" rel="noopener" class="btn-outline btn-sm mt-3"><x-ui.icon name="printer" class="w-4 h-4" /> Print label</a>
                    @endif
                </div>

                @php($advQuote = app(\App\Services\Payments\InstantPayoutService::class)->quote($item))
                @php($advance = \App\Models\PayoutAdvance::where('order_item_id', $item->id)->first())
                @if ($advance)
                    <div class="card p-6">
                        <h2 class="font-bold">Instant payout</h2>
                        <p class="mt-2 text-sm">You pulled <span class="font-semibold">${{ number_format($advance->net_paid, 2) }}</span> forward{{ $advance->fee > 0 ? ' ($'.number_format($advance->fee, 2).' fee)' : '' }} on {{ $advance->created_at->format('M j') }}.</p>
                        <p class="mt-1 text-xs text-muted">{{ $advance->status === 'recouped' ? 'Settled against the escrow release.' : 'Recouped automatically when the buyer confirms delivery.' }}</p>
                    </div>
                @elseif ($advQuote['eligible'])
                    <form method="POST" action="{{ route('seller.orders.instant-payout', $item) }}" class="card space-y-2 p-6">
                        @csrf
                        <h2 class="font-bold">Get paid now</h2>
                        <p class="text-sm text-muted">Skip the wait for delivery confirmation. We front the cash and recoup it on release.</p>
                        <div class="grid grid-cols-3 gap-2 text-center text-sm">
                            <div class="rounded-lg bg-surface-2 py-2"><p class="text-xs text-muted">Payout</p><p class="font-bold">${{ number_format($advQuote['gross'], 2) }}</p></div>
                            <div class="rounded-lg bg-surface-2 py-2"><p class="text-xs text-muted">Fee</p><p class="font-bold">${{ number_format($advQuote['fee'], 2) }}</p></div>
                            <div class="rounded-lg bg-accent/10 py-2"><p class="text-xs text-muted">You get now</p><p class="font-bold text-accent">${{ number_format($advQuote['net'], 2) }}</p></div>
                        </div>
                        <button class="btn-accent btn-sm">Pay me ${{ number_format($advQuote['net'], 2) }} now</button>
                        @if ($advQuote['fee'] == 0)<p class="text-xs text-emerald-600">Fee-free — a {{ auth()->user()->sellerProfile->tierLabel() }} perk.</p>@endif
                    </form>
                @endif
            @endif

            @if ($item->status->value === 'cancelled')
                <div class="card border-rose-500/30 p-6">
                    <h2 class="font-bold text-rose-600">Cancelled</h2>
                    <p class="mt-2 text-sm">
                        {{ \App\Services\Orders\OrderCancellationService::reasonLabel($item->cancel_reason) }}
                        @if ($item->cancelled_at) · {{ $item->cancelled_at->format('M j, Y g:i A') }} @endif
                    </p>
                    @if ($item->cancel_note)<p class="mt-1 text-sm text-muted">"{{ $item->cancel_note }}"</p>@endif
                </div>
            @endif

            @if (in_array($item->status->value, ['placed', 'paid', 'seller_accepted']) && \App\Support\SellerContext::can('orders.manage'))
                @php($cancellableSiblings = $siblings->filter(fn ($s) => in_array($s->status->value, ['placed', 'paid', 'seller_accepted']))->count())
                <div class="flex items-center justify-between gap-3 rounded-xl border border-dashed border-line px-5 py-4">
                    <p class="text-sm text-muted">Can't fulfil this one? Cancelling refunds the buyer in full and restocks the item.</p>
                    <button type="button" onclick="document.getElementById('cancel-{{ $item->id }}').showModal()" class="btn-outline btn-sm shrink-0 hover:!border-rose-500 hover:!text-rose-600">Cancel order</button>
                </div>
                @include('seller.orders.partials.cancel-dialog', ['item' => $item, 'siblingCount' => $cancellableSiblings])
            @endif

            @if ($siblings->isNotEmpty())
                <div class="card p-6">
                    <h2 class="font-bold">Also from you on this order</h2>
                    <ul class="mt-3 divide-y divide-line text-sm">
                        @foreach ($siblings as $sib)
                            <li class="flex items-center justify-between gap-3 py-2">
                                <a href="{{ route('seller.orders.show', $sib) }}" class="flex min-w-0 items-center gap-3 hover:text-accent">
                                    <x-order-thumb :item="$sib" size="h-9 w-9" />
                                    <span class="min-w-0">{{ $sib->product_title }} <span class="text-muted">× {{ $sib->quantity }}</span></span>
                                </a>
                                <span class="pill">{{ $sib->status->label() }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- returns --}}
            @if ($item->returnRequests->isNotEmpty())
                <div class="card p-6">
                    <h2 class="font-bold">Returns</h2>
                    @foreach ($item->returnRequests as $rma)
                        <div class="mt-3 flex items-center justify-between rounded-lg border border-line px-3 py-2 text-sm">
                            <div>
                                <p class="font-medium">{{ $rma->rma_number }} — {{ ucfirst($rma->status) }}</p>
                                <p class="text-xs text-muted">{{ $rma->reason }}</p>
                            </div>
                            <a href="{{ route('seller.returns.index') }}" class="text-xs font-medium text-fg underline underline-offset-2">Manage</a>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- timeline --}}
            <div class="card p-6">
                <h2 class="font-bold">History</h2>
                <ol class="mt-3 space-y-3 text-sm">
                    @forelse ($item->statusHistory->sortBy('created_at') as $h)
                        <li class="flex gap-3">
                            <span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-accent"></span>
                            <div>
                                <p class="font-medium">{{ \App\Enums\OrderStatus::tryFrom($h->status)?->label() ?? $h->status }}</p>
                                @if ($h->note) <p class="text-xs text-muted">{{ $h->note }}</p> @endif
                                <p class="text-xs text-muted">{{ $h->created_at->format('M j, Y g:i A') }}</p>
                            </div>
                        </li>
                    @empty
                        <li class="text-muted">No history yet.</li>
                    @endforelse
                </ol>
            </div>
        </div>

        {{-- ship to --}}
        <aside class="card h-fit p-6">
            <h2 class="font-bold">Ship to</h2>
            <address class="mt-3 not-italic text-sm leading-relaxed">
                <span class="font-medium">{{ $addr['name'] ?? $item->order->contactName() ?? '—' }}</span><br>
                {{ $addr['line1'] ?? '' }}<br>
                @if (!empty($addr['line2'])){{ $addr['line2'] }}<br>@endif
                {{ $addr['city'] ?? '' }}{{ !empty($addr['state']) ? ', '.$addr['state'] : '' }} {{ $addr['postal_code'] ?? '' }}<br>
                {{ $addr['country'] ?? '' }}
            </address>
            <p class="mt-4 border-t border-line pt-3 text-xs text-muted">
                Placed {{ optional($item->order->placed_at ?? $item->order->created_at)->format('M j, Y') }}
            </p>

            <div class="mt-4 border-t border-line pt-4">
                <h2 class="font-bold">Buyer</h2>
                <p class="mt-2 text-sm font-medium">{{ $item->order->contactName() ?: 'Guest' }}</p>
                @if ($item->order->buyer)
                    <div class="mt-3 flex flex-col gap-2">
                        <a href="{{ route('seller.customers.show', $item->order->buyer) }}" class="btn-outline btn-sm justify-center"><x-ui.icon name="user" class="w-4 h-4" /> Customer profile</a>
                    </div>
                @else
                    <p class="mt-1 text-xs text-muted">Checked out as a guest.</p>
                @endif
            </div>
        </aside>
    </div>
</div>
@endsection
