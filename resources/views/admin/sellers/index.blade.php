@extends('layouts.admin')

@section('title', 'Sellers')

@section('content')
<x-admin.header title="Sellers" subtitle="Approve applications, set commission, hold payouts, and suspend stores." />

<x-admin.tabs :tabs="$tabs" :current="$status" />

<x-admin.filters :search="request('q', '')" placeholder="Store name or email">
    @if ($status)<input type="hidden" name="status" value="{{ $status }}">@endif
    <select name="tier" class="input h-9 !w-auto !py-0 text-[13px]" aria-label="Tier">
        <option value="">All tiers</option>
        @foreach ($tiers as $key => $t)<option value="{{ $key }}" @selected(request('tier') === $key)>{{ $t['label'] }}</option>@endforeach
    </select>
</x-admin.filters>

@if ($status === 'started')
<x-admin.card flush>
    @if ($started->isEmpty())
        <x-admin.empty icon="store" title="Nobody is part-way through sign-up" text="People who open seller sign-up but don't set up their store show here." />
    @else
        <p class="px-4 pt-4 text-sm text-muted">These people started selling but never set up their store (business name, shipping, payouts), so they have no store yet. They get one automatic reminder a day after signing up.</p>
        <table class="adm-table">
            <thead><tr><th>Person</th><th>Signed up</th><th>Email confirmed</th><th>Reminded</th><th></th></tr></thead>
            <tbody>
                @foreach ($started as $u)
                    <tr>
                        <td>{{ $u->name }}<p class="text-xs text-muted">{{ $u->email }}</p></td>
                        <td class="whitespace-nowrap text-muted">{{ $u->created_at->format('M j, Y g:i a') }}</td>
                        <td>{{ $u->email_verified_at ? 'Yes' : 'Not yet' }}</td>
                        <td class="whitespace-nowrap text-muted">{{ $u->seller_signup_reminded_at?->format('M j, Y') ?? '—' }}</td>
                        <td class="whitespace-nowrap text-right">
                            <x-admin.action :url="route('admin.sellers.remind-signup', $u)" label="Send reminder" size="xs" :title="'Remind '.$u->email" body="We email them a link to finish setting up their store." confirm="Send" />
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-admin.card>
<div class="mt-4">{{ $started->links() }}</div>
@else
<x-admin.card flush>
    @if ($sellers->isEmpty())
        <x-admin.empty icon="store" title="No sellers here" text="Nothing matches this tab and search." />
    @else
        <table class="adm-table">
            <thead><tr><th>Store</th><th>Status</th><th>Tier</th><th class="num">Commission</th><th class="num">Live listings</th><th class="num">Sales</th><th>Applied</th><th></th></tr></thead>
            <tbody>
                @foreach ($sellers as $s)
                    <tr>
                        <td>
                            <a href="{{ route('admin.sellers.show', $s) }}" class="font-medium hover:text-accent">{{ $s->business_name }}</a>
                            <p class="text-xs text-muted">{{ $s->user?->email }}</p>
                        </td>
                        <td class="whitespace-nowrap">
                            <x-admin.status :value="$s->status" />
                            @if ($s->payouts_held)<x-admin.status value="held" label="Payouts held" />@endif
                        </td>
                        <td class="whitespace-nowrap text-muted">{{ $s->tierLabel() }}</td>
                        <td class="num">{{ rtrim(rtrim(number_format($s->commissionRate(), 2), '0'), '.') }}%@if ($s->commission_rate_override !== null)<span class="text-accent" title="Set just for this store"> *</span>@endif</td>
                        <td class="num">{{ number_format($listings[$s->user_id] ?? 0) }}</td>
                        <td class="num">${{ number_format((float) ($sales[$s->user_id] ?? 0), 2) }}</td>
                        <td class="whitespace-nowrap text-muted">{{ $s->created_at->format('M j, Y') }}</td>
                        <td class="text-right">
                            @if ($s->status === 'pending')
                                <x-admin.action :url="route('admin.sellers.approve', $s)" label="Approve" tone="primary" size="xs" :body="'Approve '.$s->business_name.' to sell on Panmotive?'" />
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-admin.card>
<div class="mt-4">{{ $sellers->links() }}</div>
@endif
@endsection
