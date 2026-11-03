@extends('layouts.admin')

@section('title', 'Returns')

@section('content')
<x-admin.header title="Returns" subtitle="Sellers handle returns first. When a buyer escalates or a seller misses the deadline, it lands here." />
<x-admin.tabs :tabs="$tabs" :current="$tab" />
<x-admin.filters :search="request('q', '')" placeholder="Return number"><input type="hidden" name="status" value="{{ $tab }}"></x-admin.filters>

@if ($returns->isEmpty())
    <x-admin.card><x-admin.empty icon="refresh" title="Nothing here" text="No returns match this tab." /></x-admin.card>
@else
    <div class="space-y-3">
        @foreach ($returns as $rma)
            @php($item = $rma->orderItem)
            <x-admin.card>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-semibold">{{ $rma->rma_number }}</p>
                            <x-admin.status :value="$rma->status" />
                            @if ($rma->escalated_at && ! $rma->resolved_at)<x-admin.status value="escalated" />@endif
                            @if ($rma->isOverdue())<x-admin.status value="failed" label="Seller overdue" />@endif
                        </div>
                        <p class="mt-1 text-[13px]">{{ $item?->product_title }} · ${{ number_format((float) $item?->line_total, 2) }}</p>
                        <p class="mt-0.5 text-xs text-muted">
                            {{ $rma->user?->name ?? 'Guest' }} ·
                            @if ($item?->order)<a href="{{ route('admin.orders.show', $item->order) }}" class="hover:text-accent">{{ $item->order->order_number }}</a> ·@endif
                            {{ $item?->seller?->sellerProfile?->business_name }} · {{ $rma->created_at->format('M j, Y') }}
                        </p>
                        <p class="mt-2 text-[13px]"><span class="font-medium">{{ ucfirst(str_replace('_', ' ', (string) $rma->reason)) }}</span>@if ($rma->detail)<span class="text-muted">: {{ $rma->detail }}</span>@endif</p>
                        @if ($rma->resolution_note)<p class="mt-1 text-xs text-muted">Note: {{ $rma->resolution_note }}</p>@endif
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($service->canDo($rma, 'approve'))
                            <x-admin.action :url="route('admin.returns.act', $rma)" label="Approve return" size="xs" body="The buyer can send it back."><input type="hidden" name="action" value="approve"></x-admin.action>
                        @endif
                        @if ($service->canDo($rma, 'reject'))
                            <x-admin.action :url="route('admin.returns.act', $rma)" label="Reject" size="xs" tone="danger" reason reason-name="note" reason-label="Reason (the buyer will see this)"><input type="hidden" name="action" value="reject"></x-admin.action>
                        @endif
                        @can('refunds.issue')
                            @if ($service->canDo($rma, 'refund'))
                                <x-admin.action :url="route('admin.returns.act', $rma)" label="Refund" size="xs" tone="primary" body="Refunds what the buyer paid. If the seller was paid, that payout is pulled back. This moves real money." confirm="Refund buyer"><input type="hidden" name="action" value="refund"></x-admin.action>
                            @endif
                            @if ($service->canDo($rma, 'refund_without_return'))
                                <x-admin.action :url="route('admin.returns.act', $rma)" label="Refund, keep item" size="xs" body="The buyer is refunded and doesn't send it back. This moves real money." confirm="Refund buyer"><input type="hidden" name="action" value="refund_without_return"></x-admin.action>
                            @endif
                        @endcan
                    </div>
                </div>
            </x-admin.card>
        @endforeach
    </div>
    <div class="mt-4">{{ $returns->links() }}</div>
@endif
@endsection
