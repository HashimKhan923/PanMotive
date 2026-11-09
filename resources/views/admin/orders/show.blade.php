@extends('layouts.admin')

@section('title', $order->order_number)

@section('content')
@php
    use App\Enums\OrderStatus;
    $me = auth()->user();
    $address = is_array($order->shipping_address) ? $order->shipping_address : [];
@endphp
<x-admin.header :title="'Order '.$order->order_number" :subtitle="'Placed '.$order->created_at->format('M j, Y g:i A')" :back="route('admin.orders.index')">
    <x-admin.status :value="$order->status" :label="$order->status->label()" class="!text-xs" />
</x-admin.header>

<div class="grid gap-4 xl:grid-cols-3">
    <div class="space-y-4 xl:col-span-2">
        @foreach ($order->items as $item)
            <x-admin.card>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <x-order-thumb :item="$item" size="h-16 w-16" class="rounded-xl" />
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($item->product && $me->can('catalog.manage'))
                                <a href="{{ route('admin.products.show', $item->product_id) }}" class="font-semibold hover:text-accent">{{ $item->product_title }}</a>
                            @else
                                <span class="font-semibold">{{ $item->product_title }}</span>
                            @endif
                            <x-admin.status :value="$item->status" :label="$item->status->label()" />
                            @if ($item->isLate())<x-admin.status value="escalated" label="Late to ship" />@endif
                        </div>
                        <p class="mt-1 text-xs text-muted">
                            {{ $item->variant_label ? $item->variant_label.' · ' : '' }}Qty {{ $item->quantity }} ·
                            @if ($item->seller?->sellerProfile && $me->can('sellers.approve'))
                                <a href="{{ route('admin.sellers.show', $item->seller->sellerProfile) }}" class="hover:text-accent">{{ $item->seller->sellerProfile->business_name }}</a>
                            @else
                                {{ $item->seller?->sellerProfile?->business_name ?? 'Unknown seller' }}
                            @endif
                            @if ($item->tracking_number) · {{ $item->carrier }} {{ $item->tracking_number }} @endif
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @can('disputes.resolve')
                            @if (in_array($item->status, OrderStatus::deliverable(), true))
                                <x-admin.action :url="route('admin.order-items.delivered', $item)" label="Mark delivered" size="xs" reason body="Use when the carrier never reported delivery. The seller payout starts." />
                            @endif
                        @endcan
                        @can('refunds.issue')
                            @if (in_array($item->status, OrderStatus::cancellable(), true) && ! $item->payout_released_at)
                                <x-admin.action :url="route('admin.order-items.cancel', $item)" label="Cancel line" size="xs" :title="'Cancel '.$item->product_title" confirm="Cancel line" body="The buyer is refunded and told, and the stock goes back." tone="danger">
                                    <div><label class="label">Reason</label><select name="reason" class="input text-sm" required>
                                        @foreach ($cancelReasons as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                                    </select></div>
                                    <div><label class="label">Note</label><input name="note" maxlength="500" class="input text-sm"></div>
                                </x-admin.action>
                            @endif
                            @if (! in_array($item->status, [OrderStatus::Refunded, OrderStatus::Cancelled, OrderStatus::Placed], true))
                                <x-admin.action :url="route('admin.order-items.refund', $item)" label="Refund" size="xs" tone="danger" reason confirm="Refund buyer" :title="'Refund $'.number_format($paidFor[$item->id], 2)"
                                                :body="$item->payout_released_at ? 'The seller was already paid, so that payout is pulled back from them first. This moves real money.' : 'The buyer gets back what they paid for this line, the same way they paid. This moves real money.'">
                                    <label class="flex items-center gap-2 text-[13px]"><input type="checkbox" name="include_shipping" value="1" class="rounded text-accent focus:ring-accent"> Also refund the order's ${{ number_format((float) $order->shipping_total, 2) }} shipping</label>
                                </x-admin.action>
                            @endif
                        @endcan
                    </div>
                </div>

                <dl class="mt-4 grid grid-cols-2 gap-3 border-t border-line/60 pt-3 text-[13px] sm:grid-cols-4">
                    <div><dt class="text-xs text-muted">Line total</dt><dd class="font-semibold tabular-nums">${{ number_format((float) $item->line_total, 2) }}</dd></div>
                    <div><dt class="text-xs text-muted">Commission ({{ rtrim(rtrim(number_format((float) $item->commission_rate, 2), '0'), '.') }}%)</dt><dd class="font-semibold tabular-nums">${{ number_format((float) $item->platform_fee_amount, 2) }}</dd></div>
                    <div><dt class="text-xs text-muted">Seller payout</dt><dd class="font-semibold tabular-nums">${{ number_format((float) $item->seller_payout_amount, 2) }}</dd></div>
                    <div><dt class="text-xs text-muted">Payout status</dt><dd class="font-semibold">{{ $item->payout_released_at ? 'Paid '.$item->payout_released_at->format('M j') : 'Not paid' }}</dd></div>
                </dl>
            </x-admin.card>
        @endforeach

        <x-admin.card title="History">
            @if ($timeline->isEmpty())
                <p class="text-sm text-muted">No history recorded yet.</p>
            @else
                <ol class="space-y-3 border-l border-line pl-4">
                    @foreach ($timeline as $event)
                        <li class="relative text-[13px]">
                            <span class="absolute -left-[21px] top-1.5 h-2 w-2 rounded-full bg-accent ring-4 ring-surface"></span>
                            <p class="font-medium">{{ ucfirst(str_replace('_', ' ', $event->status instanceof \BackedEnum ? $event->status->value : $event->status)) }}</p>
                            @if ($event->note)<p class="text-muted">{{ $event->note }}</p>@endif
                            <p class="text-xs text-muted">{{ $event->created_at->format('M j, Y g:i A') }}</p>
                        </li>
                    @endforeach
                </ol>
            @endif
            <form method="POST" action="{{ route('admin.orders.note', $order) }}" class="mt-4 flex flex-wrap gap-2 border-t border-line/60 pt-4">
                @csrf
                <select name="order_item_id" class="input h-9 !w-auto !py-0 text-[13px]" aria-label="Which item">
                    @foreach ($order->items as $item)<option value="{{ $item->id }}">{{ \Illuminate\Support\Str::limit($item->product_title, 30) }}</option>@endforeach
                </select>
                <input name="note" maxlength="1000" required placeholder="Add a staff note" aria-label="Staff note" class="input h-9 min-w-[200px] flex-1 !py-0 text-[13px]">
                <button class="btn-outline btn-sm">Add note</button>
            </form>
        </x-admin.card>
    </div>

    <div class="space-y-4">
        <x-admin.card title="Payment">
            <dl class="space-y-2 text-[13px] tabular-nums">
                <div class="flex justify-between"><dt class="text-muted">Items</dt><dd>${{ number_format((float) $order->subtotal, 2) }}</dd></div>
                <div class="flex justify-between"><dt class="text-muted">Shipping</dt><dd>${{ number_format((float) $order->shipping_total, 2) }}</dd></div>
                <div class="flex justify-between"><dt class="text-muted">Tax</dt><dd>${{ number_format((float) $order->tax_total, 2) }}</dd></div>
                <div class="flex justify-between"><dt class="text-muted">Discount</dt><dd>−${{ number_format((float) $order->discount_total, 2) }}</dd></div>
                <div class="flex justify-between"><dt class="text-muted">Store credit</dt><dd>−${{ number_format((float) $order->credits_used, 2) }}</dd></div>
                <div class="flex justify-between border-t border-line/60 pt-2 font-semibold"><dt>{{ $order->status === OrderStatus::Placed ? 'To pay by card (not paid yet)' : 'Paid by card' }}</dt><dd>${{ number_format((float) $order->total, 2) }}</dd></div>
            </dl>
            @if ($order->stripe_payment_intent_id)
                <p class="mt-3 truncate text-xs text-muted" title="{{ $order->stripe_payment_intent_id }}">Stripe {{ $order->stripe_payment_intent_id }}</p>
            @endif
        </x-admin.card>

        <x-admin.card title="Buyer">
            @if ($order->buyer)
                @can('users.view')
                    <a href="{{ route('admin.users.show', $order->buyer) }}" class="font-medium hover:text-accent">{{ $order->buyer->name }}</a>
                @else
                    <p class="font-medium">{{ $order->buyer->name }}</p>
                @endcan
                <p class="text-xs text-muted">{{ $order->buyer->email }}</p>
            @else
                <p class="font-medium">{{ $order->guest_name ?? 'Guest' }}</p>
                <p class="text-xs text-muted">{{ $order->guest_email }}</p>
            @endif
        </x-admin.card>

        <x-admin.card title="Ship to">
            <address class="space-y-0.5 text-[13px] not-italic text-muted">
                @foreach (collect($address)->only(['name', 'line1', 'line2', 'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'zip', 'country', 'phone'])->filter() as $line)
                    <p>{{ is_scalar($line) ? $line : '' }}</p>
                @endforeach
            </address>
        </x-admin.card>
    </div>
</div>
@endsection
