@extends('layouts.storefront')

@section('title', 'Order ' . $order->order_number . ' — ' . config('app.name'))

@section('content')
<div class="container-x max-w-3xl py-10" data-reveal>
    <a href="{{ route('orders.index') }}" class="inline-flex items-center gap-1 text-sm text-muted hover:text-accent">
        <x-ui.icon name="chevron-right" class="w-4 h-4 rotate-180" /> All orders
    </a>

    <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">Order {{ $order->order_number }}</h1>
            <p class="mt-1 text-sm text-muted">Placed {{ optional($order->placed_at ?? $order->created_at)->format('M j, Y') }}</p>
        </div>
        <span class="pill !bg-surface-2 !text-fg">{{ $order->status->value === 'placed' ? 'Awaiting payment' : $order->status->label() }}</span>
    </div>

    @if ($order->status->value === 'placed')
        @php($payBy = ($order->created_at)->copy()->addHours((int) config('marketplace.unpaid_order_hours', 48)))
        <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-accent/40 bg-accent/10 px-4 py-3">
            <div class="text-sm">
                <p class="font-semibold">This order hasn't been paid yet.</p>
                <p class="text-muted">Pay by {{ $payBy->format('M j, g:i A') }} or it will be cancelled and the items released.</p>
            </div>
            <a href="{{ route('orders.pay', $order) }}" class="btn-accent btn-sm">Pay ${{ number_format($order->total, 2) }} now</a>
        </div>
    @endif

    @if (session('success'))
        <p class="mt-4 rounded-xl bg-emerald-500/10 px-4 py-3 text-sm text-emerald-600">{{ session('success') }}</p>
    @endif
    @if ($errors->any())
        <p class="mt-4 rounded-xl bg-rose-500/10 px-4 py-3 text-sm text-rose-600">{{ $errors->first() }}</p>
    @endif

    <div class="mt-8 space-y-4">
        @foreach ($order->items as $item)
            <div class="card p-5">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="font-semibold">{{ $item->product_title }}</p>
                        <p class="mt-0.5 text-sm text-muted">Qty {{ $item->quantity }} · ${{ number_format($item->line_total, 2) }}</p>
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-1.5">
                        <span class="pill">{{ $item->status->label() }}</span>
                        @if ($item->seller_id)
                            <form method="POST" action="{{ route('orders.contact-seller', $item) }}">
                                @csrf
                                <button class="inline-flex items-center gap-1 text-xs font-medium text-muted hover:text-accent">
                                    <x-ui.icon name="mail" class="w-4 h-4" /> Contact seller
                                </button>
                            </form>
                        @endif
                    </div>
                </div>

                @if ($item->tracking_number)
                    <p class="mt-2 inline-flex items-center gap-1.5 text-sm">
                        <x-ui.icon name="truck" class="w-4 h-4 text-muted" /> {{ $item->carrier }} — <span class="font-mono">{{ $item->tracking_number }}</span>
                        @if ($item->trackingUrl())<a href="{{ $item->trackingUrl() }}" target="_blank" rel="noopener" class="ml-1 font-medium text-accent hover:underline">Track package ↗</a>@endif
                    </p>
                @endif

                @php($activeReturn = $item->activeReturn())

                @if ($activeReturn)
                    <div class="mt-4 rounded-xl border border-line bg-surface-2 p-3 text-sm">
                        <div class="flex items-center justify-between">
                            <p class="font-medium">Return {{ $activeReturn->rma_number }}</p>
                            <span class="pill capitalize">{{ $activeReturn->status }}</span>
                        </div>
                        <p class="mt-0.5 text-muted">
                            {{ $activeReturn->reason }}
                            @if ($activeReturn->resolution_note) — {{ $activeReturn->resolution_note }} @endif
                        </p>
                        @if ($activeReturn->fit_guarantee)
                            <p class="mt-1.5 flex items-center gap-1.5 text-xs font-medium text-emerald-600"><x-ui.icon name="shield" class="h-4 w-4" /> Covered by the Fit Guarantee: full refund, free return shipping.</p>
                        @endif

                        <ol class="mt-2 space-y-1 border-t border-line pt-2 text-xs text-muted">
                            @foreach ($activeReturn->events as $e)
                                <li><span class="font-medium capitalize text-fg">{{ $e->event }}</span> · {{ ucfirst($e->actor_role) }} · {{ $e->created_at->format('M j') }}@if ($e->note) — {{ $e->note }}@endif</li>
                            @endforeach
                        </ol>

                        @if ($activeReturn->escalated_at)
                            <p class="mt-2 text-xs font-medium text-accent">Escalated to Panmotive — a specialist is reviewing.</p>
                        @elseif (in_array($activeReturn->status, ['requested', 'approved'], true))
                            <form method="POST" action="{{ route('orders.return.escalate', $activeReturn) }}" class="mt-2">
                                @csrf
                                <button class="text-xs font-medium text-fg">Not resolving? Escalate to Panmotive</button>
                            </form>
                        @endif
                    </div>
                @endif

                @if (in_array($item->status->value, ['shipped', 'out_for_delivery'], true))
                    <div class="mt-4">
                        <form method="POST" action="{{ route('orders.confirm-delivery', $item) }}">
                            @csrf
                            <button class="btn bg-emerald-500 text-white hover:bg-emerald-400 btn-sm">Confirm delivery</button>
                        </form>
                    </div>
                @endif

                @if (! $activeReturn && in_array($item->status->value, ['delivered', 'payout_released'], true) && ! $item->isWithinReturnWindow())
                    <p class="mt-4 text-xs text-muted">Return window closed {{ $item->returnDeadline()->format('M j, Y') }}.</p>
                @elseif (! $activeReturn && in_array($item->status->value, ['delivered', 'payout_released'], true))
                    <div class="mt-4">
                        <button type="button" onclick="document.getElementById('return-{{ $item->id }}').showModal()" class="btn-outline btn-sm">Request return</button>
                        @if ($item->returnDeadline())<span class="ml-2 text-xs text-muted">until {{ $item->returnDeadline()->format('M j') }}</span>@endif
                        <dialog id="return-{{ $item->id }}" class="card max-w-sm p-6 backdrop:bg-black/50">
                            <form method="POST" action="{{ route('orders.return', $item) }}" class="space-y-3">
                                @csrf
                                <h3 class="font-semibold">Request a return</h3>
                                <label class="label" for="rr-reason-{{ $item->id }}">Reason</label>
                                @if ($item->fit_confirmed)
                                    <p class="flex items-start gap-1.5 rounded-lg bg-emerald-500/10 p-2.5 text-xs text-emerald-700 dark:text-emerald-400">
                                        <x-ui.icon name="shield" class="mt-px h-4 w-4 shrink-0" />
                                        <span>Listed as fitting your {{ $item->fit_vehicle_label }}, so the <a href="{{ route('fit-guarantee') }}" class="font-semibold underline">Fit Guarantee</a> covers it: full refund and free return shipping if it doesn't fit.</span>
                                    </p>
                                @endif
                                <select id="rr-reason-{{ $item->id }}" name="reason" class="input text-sm" required>
                                    <option value="">Choose a reason…</option>
                                    @if ($item->fit_confirmed)
                                        <option>{{ \App\Services\FitGuarantee::REASON }}</option>
                                    @else
                                        <option>Doesn't fit my vehicle</option>
                                    @endif
                                    <option>{{ \App\Services\FitGuarantee::WRONG_ORDER_REASON }}</option>
                                    <option>Wrong part received</option>
                                    <option>Arrived damaged or defective</option>
                                    <option>No longer needed</option>
                                    <option>Better price elsewhere</option>
                                </select>
                                <textarea name="detail" rows="3" placeholder="Add any details (optional)" class="input text-sm"></textarea>
                                <div class="flex gap-2">
                                    <button class="btn-brand btn-sm flex-1">Submit</button>
                                    <button type="button" onclick="this.closest('dialog').close()" class="btn-outline btn-sm">Cancel</button>
                                </div>
                            </form>
                        </dialog>
                    </div>
                @endif

                @if (in_array($item->status->value, ['shipped', 'out_for_delivery', 'delivered', 'payout_released', 'return_requested', 'return_approved'], true))
                    <div class="mt-2">
                        <button type="button" onclick="document.getElementById('claim-{{ $item->id }}').showModal()" class="text-xs font-medium text-muted hover:text-fg">Open a buyer-protection claim</button>
                        <dialog id="claim-{{ $item->id }}" class="card max-w-sm p-6 backdrop:bg-black/50">
                            <form method="POST" action="{{ route('orders.claim', $item) }}" class="space-y-3">
                                @csrf
                                <h3 class="font-semibold">Buyer-protection claim</h3>
                                <p class="text-xs text-muted">Use this when a return isn't the right fit — item never arrived, is not as described, or the seller isn't responding. Panmotive reviews if the seller doesn't resolve it in 72 hours.</p>
                                <label class="label" for="cl-reason-{{ $item->id }}">Reason</label>
                                <select id="cl-reason-{{ $item->id }}" name="reason" class="input text-sm" required>
                                    <option value="">Choose a reason…</option>
                                    @foreach (\App\Models\BuyerClaim::REASONS as $val => $label)
                                        <option value="{{ $val }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                                <textarea name="detail" rows="3" placeholder="What happened?" class="input text-sm" required></textarea>
                                <div class="flex gap-2">
                                    <button class="btn-brand btn-sm flex-1">File claim</button>
                                    <button type="button" onclick="this.closest('dialog').close()" class="btn-outline btn-sm">Cancel</button>
                                </div>
                            </form>
                        </dialog>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    @if ((float) $order->tax_total > 0)
        <div class="mt-6 flex justify-between border-t border-line pt-4 text-sm text-muted">
            <span>Sales tax</span><span>${{ number_format($order->tax_total, 2) }}</span>
        </div>
    @endif
    <div class="{{ (float) $order->tax_total > 0 ? 'mt-2' : 'mt-6 border-t border-line pt-4' }} flex justify-between text-lg font-bold">
        <span>Total</span><span>${{ number_format($order->total, 2) }}</span>
    </div>
</div>
@endsection
