@extends('layouts.storefront')

@section('title', 'Wallet')

@section('content')
<div class="max-w-2xl mx-auto py-10 px-4">
    <div class="bg-slate-900 text-white rounded-2xl p-6 mb-6" data-reveal>
        <p class="text-sm text-slate-300">Available Credit</p>
        <p class="text-4xl font-bold mt-1">${{ number_format(auth()->user()->wallet_balance, 2) }}</p>
        <p class="text-xs text-slate-400 mt-2">
            {{ ucfirst(auth()->user()->loyalty_tier) }} Tier ·
            {{ app(\App\Services\Marketing\LoyaltyService::class)->cashbackRate(auth()->user()) }}% cashback on every order
        </p>
    </div>

    <div class="border rounded-xl p-4 mb-6" data-reveal>
        <h3 class="font-semibold mb-2">Refer a Friend</h3>
        <p class="text-sm text-slate-500 mb-3">You both get $10 credit when they make their first purchase.</p>
        <div class="flex gap-2">
            <input readonly value="{{ url('/register?ref='.$referralCode) }}"
                   class="flex-1 rounded-lg border-slate-300 text-sm bg-slate-50">
            <button onclick="navigator.clipboard.writeText(this.previousElementSibling.value)"
                    class="bg-amber-500 text-slate-900 font-semibold rounded-lg px-4 text-sm">Copy</button>
        </div>
    </div>

    <h3 class="font-semibold mb-3">Transaction History</h3>
    <div class="space-y-2">
        @forelse ($transactions as $tx)
            <div class="flex justify-between border-b py-2 text-sm">
                <div>
                    <p>{{ $tx->description }}</p>
                    <p class="text-xs text-slate-400">{{ $tx->created_at->format('M j, Y') }}</p>
                </div>
                <span class="font-semibold {{ $tx->amount >= 0 ? 'text-emerald-600' : 'text-red-500' }}">
                    {{ $tx->amount >= 0 ? '+' : '' }}${{ number_format($tx->amount, 2) }}
                </span>
            </div>
        @empty
            <p class="text-sm text-slate-400">No credit activity yet.</p>
        @endforelse
    </div>
    <div class="mt-4">{{ $transactions->links() }}</div>
</div>
@endsection
