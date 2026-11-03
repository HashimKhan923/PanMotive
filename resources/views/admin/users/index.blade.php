@extends('layouts.admin')

@section('title', 'Buyers & accounts')

@section('content')
<x-admin.header title="Buyers & accounts" subtitle="Every account on Pan Motive. Open one to see orders and wallet, or to suspend it." />

<x-admin.tabs :tabs="$tabs" param="type" :current="$tab" />

<x-admin.filters :search="request('q', '')" placeholder="Name, email or phone">
    @if ($tab)<input type="hidden" name="type" value="{{ $tab }}">@endif
    <select name="sort" class="input h-9 !w-auto !py-0 text-[13px]" aria-label="Sort">
        <option value="">Newest first</option>
        <option value="spend" @selected(request('sort') === 'spend')>Highest spend</option>
    </select>
</x-admin.filters>

<x-admin.card flush>
    @if ($users->isEmpty())
        <x-admin.empty icon="users" title="No accounts match" text="Try a different search or tab." />
    @else
        <table class="adm-table">
            <thead><tr><th>Person</th><th>Type</th><th>Status</th><th class="num">Orders</th><th class="num">Lifetime spend</th><th class="num">Wallet</th><th>Joined</th></tr></thead>
            <tbody>
                @foreach ($users as $u)
                    <tr>
                        <td>
                            <a href="{{ route('admin.users.show', $u) }}" class="font-medium hover:text-accent">{{ $u->name }}</a>
                            <p class="text-xs text-muted">{{ $u->email }}</p>
                        </td>
                        <td class="capitalize text-muted">{{ str_replace('_', ' ', $u->roles->pluck('name')->first() ?? 'buyer') }}</td>
                        <td>
                            @if ($u->is_active === false)
                                <x-admin.status value="suspended" />
                            @elseif ($u->isPlusMember())
                                <x-admin.status value="active" label="Plus" />
                            @else
                                <x-admin.status value="active" />
                            @endif
                        </td>
                        <td class="num">{{ number_format($u->orders_count) }}</td>
                        <td class="num">${{ number_format((float) $u->lifetime_spend, 2) }}</td>
                        <td class="num">${{ number_format((float) $u->wallet_balance, 2) }}</td>
                        <td class="whitespace-nowrap text-muted">{{ $u->created_at->format('M j, Y') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-admin.card>
<div class="mt-4">{{ $users->links() }}</div>
@endsection
