@extends('layouts.admin')

@section('title', 'Disputes & claims')

@section('content')
<x-admin.header title="Disputes & claims" subtitle="When a buyer and a seller can't agree, Pan Motive decides. Decisions move real money." />

<x-admin.card title="Buyer claims" :subtitle="$claims->count().' open, escalated ones first'" class="mb-4">
    @if ($claims->isEmpty())
        <x-admin.empty title="No open claims" class="!py-6" />
    @else
        <ul class="-my-2 divide-y divide-line/60">
            @foreach ($claims as $claim)
                <li class="flex flex-wrap items-start justify-between gap-4 py-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-semibold">{{ $claim->claim_number }}</p>
                            <x-admin.status :value="$claim->status" />
                            <span class="text-xs text-muted">{{ ucfirst(str_replace('_', ' ', $claim->reason)) }}</span>
                        </div>
                        <p class="mt-1 text-xs text-muted">
                            {{ $claim->buyer?->name ?? 'Buyer' }} vs {{ $claim->seller?->sellerProfile?->business_name ?? 'seller' }}
                            @if ($claim->orderItem?->order) · <a href="{{ route('admin.orders.show', $claim->orderItem->order) }}" class="hover:text-accent">{{ $claim->orderItem->order->order_number }}</a>@endif
                            · ${{ number_format((float) $claim->amount, 2) }}
                        </p>
                        @if ($claim->detail)<p class="mt-2 text-[13px]">{{ $claim->detail }}</p>@endif
                        @if ($claim->seller_response)
                            <div class="mt-2 rounded-xl bg-surface-2 p-3 text-[13px]"><p class="mb-0.5 text-xs font-semibold text-muted">Seller's response</p>{{ $claim->seller_response }}</div>
                        @endif
                    </div>
                    @can('refunds.issue')
                        <div class="flex items-center gap-2">
                            <x-admin.action :url="route('admin.claims.decide', $claim)" label="Grant claim" size="xs" tone="danger" reason reason-name="note" reason-label="Decision note" confirm="Grant and refund"
                                            body="The buyer is refunded the way they paid. If the seller was paid, that payout is pulled back. The store gets a violation.">
                                <input type="hidden" name="decision" value="grant">
                            </x-admin.action>
                            <x-admin.action :url="route('admin.claims.decide', $claim)" label="Deny" size="xs" reason reason-name="note" reason-label="Decision note" body="The claim closes and no money moves.">
                                <input type="hidden" name="decision" value="deny">
                            </x-admin.action>
                        </div>
                    @endcan
                </li>
            @endforeach
        </ul>
    @endif
</x-admin.card>

<x-admin.card title="Disputed order lines" flush>
    @if ($items->isEmpty())
        <x-admin.empty title="No disputed lines" />
    @else
        <table class="adm-table">
            <thead><tr><th>Order</th><th>Item</th><th>Seller</th><th>Status</th><th class="num">Total</th><th></th></tr></thead>
            <tbody>
                @foreach ($items as $item)
                    <tr>
                        <td>
                            <a href="{{ route('admin.orders.show', $item->order) }}" class="font-medium hover:text-accent">{{ $item->order?->order_number }}</a>
                            <p class="text-xs text-muted">{{ $item->order?->buyer?->name ?? $item->order?->guest_name ?? 'Guest' }}</p>
                        </td>
                        <td class="max-w-[220px] truncate">{{ $item->product_title }}</td>
                        <td class="whitespace-nowrap text-muted">{{ $item->seller?->sellerProfile?->business_name }}</td>
                        <td class="whitespace-nowrap"><x-admin.status :value="$item->status" :label="$item->status->label()" />
                            @if ($item->payout_released_at)<x-admin.status value="held" label="Seller already paid" />@endif</td>
                        <td class="num">${{ number_format((float) $item->line_total, 2) }}</td>
                        <td class="whitespace-nowrap text-right">
                            @can('refunds.issue')
                                @if ($item->payout_released_at)
                                    <x-admin.action :url="route('admin.disputes.reverse-payout', $item)" label="Reverse payout and refund" size="xs" tone="danger" reason body="Pulls the money back from the seller, then refunds the buyer. This moves real money." confirm="Reverse and refund" />
                                @else
                                    <x-admin.action :url="route('admin.disputes.approve-return', $item)" label="Approve and refund" size="xs" tone="primary" body="Refunds what the buyer paid. This moves real money." confirm="Refund buyer" />
                                @endif
                            @endcan
                            @can('disputes.resolve')
                                <x-admin.action :url="route('admin.disputes.deny-return', $item)" label="Deny" size="xs" reason reason-label="Reason (the buyer will see this)" />
                            @endcan
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-admin.card>
<div class="mt-4">{{ $items->links() }}</div>
@endsection
