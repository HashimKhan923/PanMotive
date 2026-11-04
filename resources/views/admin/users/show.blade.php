@extends('layouts.admin')

@section('title', $user->name)

@section('content')
@php($me = auth()->user())
<x-admin.header :title="$user->name" :subtitle="$user->email.($user->phone ? ' · '.$user->phone : '')" :back="route('admin.users.index')">
    @if ($me->can('users.impersonate') && ! $isStaff && $user->is_active !== false)
        <x-admin.action :url="route('admin.users.impersonate', $user)" label="View as this person" icon="user" reason
                        reason-label="Why do you need to view this account?"
                        body="You will see the site as they do. Spending and account changes are blocked, and this is written to the audit log." />
    @endif
    @if ($me->can('users.manage') && (! $isStaff || $me->can('staff.manage')))
        <x-admin.action :url="route('admin.users.password-reset', $user)" label="Send password reset" icon="mail" :body="'A reset link goes to '.$user->email.'.'" confirm="Send link" />
        @if ($user->is_active === false)
            <x-admin.action :url="route('admin.users.unban', $user)" label="Restore account" tone="primary" body="They will be able to sign in again." />
        @elseif (! $user->is($me))
            <x-admin.action :url="route('admin.users.ban', $user)" label="Suspend account" tone="danger" reason
                            body="They are signed out immediately and can't sign in. Their past orders stay on record." confirm="Suspend" />
        @endif
    @endif
</x-admin.header>

@if ($user->is_active === false)
    <div class="mb-4 flex items-center gap-2 rounded-xl border border-rose-500/30 bg-rose-500/10 px-4 py-3 text-sm text-rose-600 dark:text-rose-300">
        <x-ui.icon name="alert" class="w-4 h-4 shrink-0" /> This account is suspended: {{ $user->banned_reason ?: 'no reason recorded' }}.
    </div>
@endif

<div class="grid grid-cols-2 gap-3 lg:grid-cols-4" data-enter>
    <x-admin.stat label="Orders" :value="$orderCount" icon="list" />
    <x-admin.stat label="Paid order value" :value="$orderTotal" prefix="$" :decimals="2" icon="dollar" />
    <x-admin.stat label="Wallet balance" :value="(float) $user->wallet_balance" prefix="$" :decimals="2" icon="archive" />
    <x-admin.stat label="Loyalty tier" :value="ucfirst($user->loyalty_tier ?? 'bronze')" icon="star" :hint="$user->isPlusMember() ? 'Plus until '.$user->plus_until->format('M j, Y') : 'Not a Plus member'" />
</div>

<div class="mt-4 grid gap-4 xl:grid-cols-3">
    <div class="space-y-4 xl:col-span-2">
        <x-admin.card title="Orders" flush>
            @if ($orders->isEmpty())
                <x-admin.empty icon="list" title="No orders yet" />
            @else
                <table class="adm-table">
                    <thead><tr><th>Order</th><th>Status</th><th>Date</th><th class="num">Total</th></tr></thead>
                    <tbody>
                        @foreach ($orders as $o)
                            <tr>
                                <td>
                                    @can('orders.view')
                                        <a href="{{ route('admin.orders.show', $o) }}" class="font-medium hover:text-accent">{{ $o->order_number }}</a>
                                    @else
                                        {{ $o->order_number }}
                                    @endcan
                                </td>
                                <td><x-admin.status :value="$o->status" /></td>
                                <td class="text-muted">{{ $o->created_at->format('M j, Y') }}</td>
                                <td class="num">${{ number_format((float) $o->total, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-admin.card>

        <x-admin.card title="Wallet history" flush>
            @if ($credits->isEmpty())
                <x-admin.empty icon="archive" title="No wallet activity" />
            @else
                <table class="adm-table">
                    <thead><tr><th>What</th><th>Date</th><th class="num">Amount</th><th class="num">Balance</th></tr></thead>
                    <tbody>
                        @foreach ($credits as $c)
                            <tr>
                                <td>{{ $c->description }}</td>
                                <td class="whitespace-nowrap text-muted">{{ $c->created_at->format('M j, Y') }}</td>
                                <td class="num">{{ $c->amount < 0 ? '−' : '+' }}${{ number_format(abs((float) $c->amount), 2) }}</td>
                                <td class="num">${{ number_format((float) $c->balance_after, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-admin.card>
    </div>

    <div class="space-y-4">
        @if ($user->sellerProfile && $me->can('sellers.approve'))
            <a href="{{ route('admin.sellers.show', $user->sellerProfile) }}" class="adm-card flex items-center gap-3 p-4">
                <span class="grid h-10 w-10 place-items-center rounded-xl bg-accent/10 text-accent"><x-ui.icon name="store" class="w-5 h-5" /></span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-semibold">{{ $user->sellerProfile->business_name }}</span>
                    <span class="text-xs text-muted">Open seller account</span>
                </span>
                <x-ui.icon name="chevron-right" class="w-4 h-4 text-muted" />
            </a>
        @endif

        @if ($me->can('users.manage') && (! $isStaff || $me->can('staff.manage')))
            <x-admin.card title="Details">
                <form method="POST" action="{{ route('admin.users.update', $user) }}" class="space-y-3">
                    @csrf @method('PATCH')
                    <div><label class="label" for="u-name">Name</label><input id="u-name" name="name" value="{{ old('name', $user->name) }}" class="input text-sm" required></div>
                    <div><label class="label" for="u-email">Email</label><input id="u-email" type="email" name="email" value="{{ old('email', $user->email) }}" class="input text-sm" required></div>
                    <div><label class="label" for="u-phone">Phone</label><input id="u-phone" name="phone" value="{{ old('phone', $user->phone) }}" class="input text-sm"></div>
                    <button class="btn-accent btn-sm w-full">Save details</button>
                </form>
            </x-admin.card>
        @endif

        @can('wallet.adjust')
            <x-admin.card title="Adjust wallet" subtitle="Goes in their wallet history and the audit log.">
                <form method="POST" action="{{ route('admin.users.wallet', $user) }}" class="space-y-3">
                    @csrf
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="label" for="w-dir">Action</label>
                            <select id="w-dir" name="direction" class="input text-sm"><option value="add">Add credit</option><option value="remove">Remove credit</option></select>
                        </div>
                        <div><label class="label" for="w-amt">Amount ($)</label><input id="w-amt" type="number" step="0.01" min="0.01" max="10000" name="amount" class="input text-sm" required></div>
                    </div>
                    <div><label class="label" for="w-why">Reason</label><input id="w-why" name="reason" maxlength="255" class="input text-sm" required></div>
                    <button class="btn-outline btn-sm w-full">Apply adjustment</button>
                </form>
            </x-admin.card>
        @endcan

        @can('users.manage')
            <x-admin.card title="Plus membership">
                <form method="POST" action="{{ route('admin.users.plus', $user) }}" class="space-y-3">
                    @csrf
                    <div><label class="label" for="p-until">Member until <span class="font-normal text-muted">(empty ends it)</span></label>
                        <input id="p-until" type="date" name="until" value="{{ $user->plus_until?->format('Y-m-d') }}" class="input text-sm"></div>
                    <div><label class="label" for="p-why">Reason</label><input id="p-why" name="reason" maxlength="255" class="input text-sm" required></div>
                    <button class="btn-outline btn-sm w-full">Update membership</button>
                </form>
            </x-admin.card>
        @endcan

        <x-admin.card title="Buyer signals" subtitle="Patterns worth a look. High numbers alone don't prove abuse.">
            <dl class="space-y-2 text-[13px] tabular-nums">
                @foreach ($signals as $label => $count)
                    <div class="flex justify-between gap-3"><dt class="text-muted">{{ $label }}</dt><dd class="font-semibold">{{ number_format($count) }}</dd></div>
                @endforeach
            </dl>
        </x-admin.card>

        <x-admin.card title="Account">
            <dl class="space-y-2 text-[13px]">
                <div class="flex justify-between gap-3"><dt class="text-muted">Joined</dt><dd>{{ $user->created_at->format('M j, Y') }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted">Email verified</dt><dd>{{ $user->email_verified_at ? 'Yes' : 'No' }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-muted">Roles</dt><dd class="capitalize">{{ $user->roles->pluck('name')->map(fn ($r) => str_replace('_', ' ', $r))->implode(', ') ?: 'Buyer' }}</dd></div>
            </dl>
        </x-admin.card>

        @if ($history->isNotEmpty())
            <x-admin.card title="Admin history">
                <ul class="space-y-2.5 text-[13px]">
                    @foreach ($history as $h)
                        <li><p class="font-medium">{{ $h->description }}</p><p class="text-xs text-muted">{{ $h->causer?->name ?? 'System' }} · {{ $h->created_at->diffForHumans() }}</p></li>
                    @endforeach
                </ul>
            </x-admin.card>
        @endif
    </div>
</div>
@endsection
