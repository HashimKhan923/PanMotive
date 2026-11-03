@extends('layouts.admin')

@section('title', 'Subscribe & Save')

@section('content')
<x-admin.header title="Subscribe & Save" subtitle="Repeat orders buyers have set up. Pause or cancel one if there's a problem." />

<div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-3" data-enter>
    <x-admin.stat label="Active" :value="$tabs['active'][1]" icon="refresh" />
    <x-admin.stat label="Ordering in the next 7 days" :value="$dueSoon" icon="clock" />
    <x-admin.stat label="Orders created, all time" :value="$ordersMade" icon="list" />
</div>

<x-admin.tabs :tabs="$tabs" :current="$status" />
<x-admin.filters :search="request('q', '')" placeholder="Buyer name or email"><input type="hidden" name="status" value="{{ $status }}"></x-admin.filters>

<x-admin.card flush>
    @if ($subscriptions->isEmpty())
        <x-admin.empty icon="refresh" title="No subscriptions here" />
    @else
        <table class="adm-table">
            <thead><tr><th>Part</th><th>Buyer</th><th>Store</th><th>Every</th><th>Status</th><th>Next order</th><th class="num">Orders</th><th></th></tr></thead>
            <tbody>
                @foreach ($subscriptions as $s)
                    <tr>
                        <td class="max-w-[240px] truncate font-medium">{{ $s->product?->title ?? 'Removed listing' }} <span class="font-normal text-muted">× {{ $s->quantity }}</span></td>
                        <td class="whitespace-nowrap">{{ $s->buyer?->name }}<p class="text-xs text-muted">{{ $s->buyer?->email }}</p></td>
                        <td class="whitespace-nowrap text-muted">{{ $s->seller?->sellerProfile?->business_name ?? '—' }}</td>
                        <td class="whitespace-nowrap text-muted">{{ $s->interval_days }} days</td>
                        <td><x-admin.status :value="$s->status" /></td>
                        <td class="whitespace-nowrap text-muted">{{ $s->status === 'active' ? $s->next_run_at?->format('M j, Y') : '—' }}</td>
                        <td class="num">{{ number_format((int) $s->orders_count) }}</td>
                        <td class="whitespace-nowrap text-right">
                            @can('disputes.resolve')
                                @if ($s->status === 'active')
                                    <x-admin.action :url="route('admin.subscriptions.action', $s)" label="Pause" size="xs" reason title="Pause this subscription"><input type="hidden" name="action" value="pause"></x-admin.action>
                                @elseif ($s->status === 'paused')
                                    <x-admin.action :url="route('admin.subscriptions.action', $s)" label="Resume" size="xs" reason title="Resume this subscription"><input type="hidden" name="action" value="resume"></x-admin.action>
                                @endif
                                @if ($s->status !== 'cancelled')
                                    <x-admin.action :url="route('admin.subscriptions.action', $s)" label="Cancel" tone="danger" size="xs" reason title="Cancel this subscription" body="No more orders will be placed." confirm="Cancel subscription"><input type="hidden" name="action" value="cancel"></x-admin.action>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-admin.card>
<div class="mt-4">{{ $subscriptions->links() }}</div>
@endsection
