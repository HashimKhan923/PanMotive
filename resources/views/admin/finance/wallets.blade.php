@extends('layouts.admin')

@section('title', 'Wallets & credit')

@section('content')
<x-admin.header title="Wallets & credit" subtitle="Store credit is money PanMotive owes buyers. To change a balance, open the person's account." />

<div class="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4" data-enter>
    <x-admin.stat label="Total owed in wallets" :value="$walletOwed" prefix="$" :decimals="2" icon="dollar" />
    <x-admin.stat label="Buyers with a balance" :value="$holders" icon="users" />
    <x-admin.stat label="Added, last 30 days" :value="$granted" prefix="$" :decimals="2" icon="plus" />
    <x-admin.stat label="Spent, last 30 days" :value="$redeemed" prefix="$" :decimals="2" icon="cart" />
</div>

<x-admin.filters :search="request('q', '')" placeholder="Buyer name or email">
    <select name="type" class="input h-9 !w-auto !py-0 text-[13px]" aria-label="Type">
        <option value="">All types</option>
        @foreach ($types as $key => $label)<option value="{{ $key }}" @selected(request('type') === $key)>{{ $label }}</option>@endforeach
    </select>
</x-admin.filters>

<x-admin.card flush>
    @if ($transactions->isEmpty())
        <x-admin.empty icon="archive" title="No wallet activity" />
    @else
        <table class="adm-table">
            <thead><tr><th>Buyer</th><th>What</th><th>Date</th><th class="num">Amount</th><th class="num">Balance after</th></tr></thead>
            <tbody>
                @foreach ($transactions as $t)
                    <tr>
                        <td>@if ($t->user)<a href="{{ route('admin.users.show', $t->user) }}" class="font-medium hover:text-accent">{{ $t->user->name }}</a>@else — @endif</td>
                        <td class="max-w-[360px] truncate text-muted">{{ $t->description }}</td>
                        <td class="whitespace-nowrap text-muted">{{ $t->created_at->format('M j, Y') }}</td>
                        <td class="num">{{ $t->amount < 0 ? '−' : '+' }}${{ number_format(abs((float) $t->amount), 2) }}</td>
                        <td class="num">${{ number_format((float) $t->balance_after, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-admin.card>
<div class="mt-4">{{ $transactions->links() }}</div>
@endsection
