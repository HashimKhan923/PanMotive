@extends('layouts.admin')

@section('title', $profile->business_name)

@section('content')
<x-admin.header :title="$profile->business_name" :subtitle="$profile->user?->email" :back="route('admin.sellers.index')">
    <x-admin.status :value="$profile->status" />
    @if ($profile->status === 'approved')
        <a href="{{ \App\Support\AdminNav::site('sellers.show', $profile) }}" target="_blank" rel="noopener" class="btn-outline btn-sm">View store <x-ui.icon name="external" class="w-4 h-4" /></a>
    @endif
    @if ($profile->status === 'pending')
        <x-admin.action :url="route('admin.sellers.approve', $profile)" label="Approve" tone="primary" body="They can start selling straight away." />
        <x-admin.action :url="route('admin.sellers.reject', $profile)" label="Reject" tone="danger" reason reason-label="Reason (they will see this)" />
    @endif
    @can('sellers.suspend')
        @if ($profile->status === 'approved')
            <x-admin.action :url="route('admin.sellers.suspend', $profile)" label="Suspend store" tone="danger" reason reason-label="Reason (they will see this)"
                            :body="$stats['to_ship'] ? 'Listings are hidden at once. '.$stats['to_ship'].' paid order lines still need shipping: check them first.' : 'Listings are hidden from shoppers at once and they can no longer sell.'" confirm="Suspend" />
        @elseif ($profile->status === 'suspended')
            <x-admin.action :url="route('admin.sellers.reinstate', $profile)" label="Reinstate store" tone="primary" body="Their listings go live again and they can sell." />
        @endif
    @endcan
    @if ($profile->user)
        <a href="{{ route('admin.users.show', $profile->user) }}" class="btn-outline btn-sm">Owner account</a>
    @endif
</x-admin.header>

@if ($profile->payouts_held)
    <div class="mb-4 flex items-center gap-2 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-700 dark:text-amber-300">
        <x-ui.icon name="alert" class="w-4 h-4 shrink-0" /> Payouts are on hold: {{ $profile->payouts_held_reason }}
    </div>
@endif

<div class="grid grid-cols-2 gap-3 lg:grid-cols-4" data-enter>
    <x-admin.stat label="Sales" :value="$stats['sales']" prefix="$" :decimals="2" icon="dollar" hint="Excludes refunded and cancelled" />
    <x-admin.stat label="Commission earned" :value="$stats['commission']" prefix="$" :decimals="2" icon="chart" />
    <x-admin.stat label="Waiting to be paid" :value="$stats['unpaid']" prefix="$" :decimals="2" icon="archive" hint="Delivered, payout not released" />
    <x-admin.stat label="Live listings" :value="$stats['live_listings']" icon="box" :hint="number_format($stats['all_listings']).' total'" />
</div>

<div class="mt-4 grid gap-4 xl:grid-cols-3">
    <div class="space-y-4 xl:col-span-2">
        <x-admin.card title="Recent order lines" flush>
            @if ($recentLines->isEmpty())
                <x-admin.empty icon="list" title="No orders yet" />
            @else
                <table class="adm-table">
                    <thead><tr><th>Order</th><th>Item</th><th>Status</th><th class="num">Total</th><th class="num">Fee</th></tr></thead>
                    <tbody>
                        @foreach ($recentLines as $line)
                            <tr>
                                <td><a href="{{ route('admin.orders.show', $line->order) }}" class="font-medium hover:text-accent">{{ $line->order?->order_number }}</a></td>
                                <td class="max-w-[260px] truncate text-muted">{{ $line->product_title }}</td>
                                <td><x-admin.status :value="$line->status" :label="$line->status->label()" /></td>
                                <td class="num">${{ number_format((float) $line->line_total, 2) }}</td>
                                <td class="num">${{ number_format((float) $line->platform_fee_amount, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-admin.card>

        <x-admin.card title="Policy record" flush>
            <x-slot:actions>
                @can('sellers.manage')
                    <x-admin.action :url="route('admin.sellers.violation', $profile)" label="Issue violation" size="xs" icon="alert" confirm="Issue violation" body="The seller is told and can appeal it. It expires after 180 days.">
                        <div><label class="label">Title</label><input name="title" class="input text-sm" maxlength="255" required></div>
                        <div class="grid grid-cols-2 gap-2">
                            <div><label class="label">Type</label><select name="type" class="input text-sm">
                                @foreach (['policy_breach' => 'Policy breach', 'late_shipment' => 'Late shipment', 'counterfeit' => 'Counterfeit', 'prohibited_item' => 'Prohibited item'] as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
                            </select></div>
                            <div><label class="label">Severity</label><select name="severity" class="input text-sm"><option value="notice">Notice</option><option value="warning" selected>Warning</option><option value="severe">Severe</option></select></div>
                        </div>
                        <div><label class="label">Details</label><textarea name="description" rows="3" class="input text-sm" maxlength="2000"></textarea></div>
                    </x-admin.action>
                @endcan
            </x-slot:actions>
            @if ($violations->isEmpty())
                <x-admin.empty icon="shield" title="Clean record" text="No policy violations." />
            @else
                <table class="adm-table">
                    <thead><tr><th>Issue</th><th>Severity</th><th>Status</th><th>Issued</th></tr></thead>
                    <tbody>
                        @foreach ($violations as $v)
                            <tr>
                                <td class="font-medium">{{ $v->title }}</td>
                                <td class="capitalize text-muted">{{ $v->severity }}</td>
                                <td><x-admin.status :value="$v->status" /></td>
                                <td class="whitespace-nowrap text-muted">{{ $v->created_at->format('M j, Y') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-admin.card>

        @if ($history->isNotEmpty())
            <x-admin.card title="Admin history">
                <ul class="space-y-2.5 text-[13px]">
                    @foreach ($history as $h)
                        <li><p class="font-medium">{{ $h->description }}@if ($h->getExtraProperty('reason'))<span class="font-normal text-muted"> — {{ $h->getExtraProperty('reason') }}</span>@endif</p>
                            <p class="text-xs text-muted">{{ $h->causer?->name ?? 'System' }} · {{ $h->created_at->diffForHumans() }}</p></li>
                    @endforeach
                </ul>
            </x-admin.card>
        @endif
    </div>

    <div class="space-y-4">
        <x-admin.card title="Scorecard" :subtitle="'Last '.($score['window_days'] ?? 90).' days, the same numbers the seller sees'">
            <dl class="space-y-2 text-[13px] tabular-nums">
                <div class="flex justify-between gap-3"><dt class="text-muted">Fulfilled orders</dt><dd class="font-semibold">{{ number_format((int) ($score['fulfilled_orders'] ?? 0)) }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted">On-time shipping</dt><dd class="font-semibold">{{ $score['on_time_ship_rate'] ?? 0 }}%</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted">Defect rate</dt><dd class="font-semibold">{{ $score['defect_rate'] ?? 0 }}%</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted">Cancellations</dt><dd class="font-semibold">{{ $score['cancellation_rate'] ?? 0 }}%</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted">Message replies</dt><dd class="font-semibold">{{ $score['response_rate'] ?? 0 }}%</dd></div>
                <div class="flex justify-between gap-3 border-t border-line/60 pt-2"><dt class="text-muted">Earned tier</dt><dd class="font-semibold">{{ $score['tier_label'] ?? '—' }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted">Lines still to ship</dt><dd class="font-semibold">@can('orders.view')<a href="{{ route('admin.orders.index', ['seller' => $profile->user_id]) }}" class="hover:text-accent">{{ number_format($stats['to_ship']) }}</a>@else{{ number_format($stats['to_ship']) }}@endcan</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted">Refunded lines</dt><dd class="font-semibold">{{ number_format($stats['refunded']) }}</dd></div>
            </dl>
        </x-admin.card>

        <x-admin.card title="Business">
            <dl class="space-y-2 text-[13px]">
                <div class="flex justify-between gap-3"><dt class="text-muted">Type</dt><dd class="uppercase">{{ $profile->business_type }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted">Identity check</dt><dd><x-admin.status :value="$verification?->status ?? 'none'" :label="$verification ? ucfirst($verification->status) : 'Not submitted'" /></dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted">Stripe payouts</dt><dd><x-admin.status :value="$profile->stripe_onboarding_complete ? 'active' : 'pending'" :label="$profile->stripe_onboarding_complete ? 'Connected' : 'Not connected'" /></dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted">Rating</dt><dd>{{ number_format((float) $profile->rating, 1) }} ({{ number_format((int) $profile->rating_count) }})</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted">Tier</dt><dd>{{ $profile->tierLabel() }}{{ $profile->tier_locked ? ' (locked)' : '' }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted">Joined</dt><dd>{{ $profile->created_at->format('M j, Y') }}</dd></div>
            </dl>
        </x-admin.card>

        @can('sellers.manage')
            <x-admin.card title="Commission" :subtitle="'Now '.rtrim(rtrim(number_format($profile->commissionRate(), 2), '0'), '.').'%. Standard is '.rtrim(rtrim(number_format($defaultRate, 2), '0'), '.').'%.'">
                <form method="POST" action="{{ route('admin.sellers.commission', $profile) }}" class="space-y-3">
                    @csrf
                    <div><label class="label" for="c-rate">Rate for this store (%) <span class="font-normal text-muted">(empty = standard)</span></label>
                        <input id="c-rate" type="number" step="0.01" min="0" max="50" name="rate" value="{{ $profile->commission_rate_override }}" class="input text-sm"></div>
                    <div><label class="label" for="c-why">Reason</label><input id="c-why" name="reason" maxlength="255" class="input text-sm" required></div>
                    <button class="btn-outline btn-sm w-full">Save commission</button>
                    <p class="text-xs text-muted">Applies to future orders only.</p>
                </form>
            </x-admin.card>

            <x-admin.card title="Performance tier">
                <form method="POST" action="{{ route('admin.sellers.tier', $profile) }}" class="space-y-3">
                    @csrf
                    <div><label class="label" for="t-tier">Tier</label>
                        <select id="t-tier" name="tier" class="input text-sm">
                            @foreach ($tiers as $key => $t)<option value="{{ $key }}" @selected($profile->tier === $key)>{{ $t['label'] }}</option>@endforeach
                        </select></div>
                    <label class="flex items-center gap-2 text-[13px]"><input type="hidden" name="locked" value="0"><input type="checkbox" name="locked" value="1" @checked($profile->tier_locked) class="rounded text-accent focus:ring-accent"> Lock it, so automatic scoring won't change it</label>
                    <div><label class="label" for="t-why">Reason</label><input id="t-why" name="reason" maxlength="255" class="input text-sm" required></div>
                    <button class="btn-outline btn-sm w-full">Save tier</button>
                </form>
            </x-admin.card>

            <x-admin.card title="Payouts">
                @if ($profile->payouts_held)
                    <x-admin.action :url="route('admin.sellers.payout-hold', $profile)" label="Release payout hold" tone="primary" reason body="Delivered orders start paying out again." class="w-full justify-center">
                        <input type="hidden" name="hold" value="0">
                    </x-admin.action>
                @else
                    <p class="mb-3 text-xs text-muted">A hold stops money leaving for this store. Orders keep working and earnings stay safe.</p>
                    <x-admin.action :url="route('admin.sellers.payout-hold', $profile)" label="Hold payouts" tone="danger" reason reason-label="Reason (they will see this)" class="w-full justify-center">
                        <input type="hidden" name="hold" value="1">
                    </x-admin.action>
                @endif
            </x-admin.card>

            <x-admin.card title="Private staff notes" subtitle="Sellers never see these.">
                <form method="POST" action="{{ route('admin.sellers.notes', $profile) }}" class="space-y-2">
                    @csrf
                    <textarea name="notes" rows="4" maxlength="5000" class="input text-sm" aria-label="Staff notes">{{ $profile->admin_notes }}</textarea>
                    <button class="btn-outline btn-sm w-full">Save notes</button>
                </form>
            </x-admin.card>
        @endcan
    </div>
</div>
@endsection
