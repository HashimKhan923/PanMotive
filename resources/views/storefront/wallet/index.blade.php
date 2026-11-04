@extends('layouts.storefront')

@section('title', 'Wallet & Credits — ' . config('app.name'))

@section('content')
<div class="container-x max-w-2xl py-10">
    <div class="relative overflow-hidden rounded-3xl bg-brand p-7 text-brand-fg" data-reveal="scale">
        <div class="pointer-events-none absolute -right-16 -top-16 h-48 w-48 rounded-full bg-accent/25 blur-3xl"></div>
        <p class="relative text-sm text-white/80">Available credit</p>
        <p class="relative mt-1 text-4xl font-bold">${{ number_format(auth()->user()->wallet_balance, 2) }}</p>
        <p class="relative mt-2 inline-flex items-center gap-1.5 text-xs text-white/80">
            <x-ui.icon name="sparkles" class="w-4 h-4 text-fg" />
            {{ ucfirst(auth()->user()->loyalty_tier) }} tier · {{ rtrim(rtrim(number_format(app(\App\Services\Marketing\LoyaltyService::class)->cashbackRate(auth()->user()), 1), '0'), '.') }}% cashback on every order
        </p>
    </div>

    <div class="card mt-6 p-6" data-reveal>
        <h3 class="font-semibold">Refer a friend</h3>
        <p class="mt-1 text-sm text-muted">You both get $10 credit when they make their first purchase.</p>
        <div class="mt-3 flex gap-2" x-data="{ copied: false }">
            <input readonly value="{{ url('/register?ref=' . $referralCode) }}" class="input h-10 bg-surface-2 text-sm">
            <button @click="navigator.clipboard.writeText($el.previousElementSibling.value); copied = true; setTimeout(() => copied = false, 1500)" class="btn-accent btn-sm shrink-0">
                <span x-show="!copied">Copy</span><span x-show="copied" x-cloak>Copied ✓</span>
            </button>
        </div>
    </div>

    <h3 class="mb-3 mt-8 font-semibold">Activity</h3>
    <div class="card divide-y divide-line" data-reveal>
        @forelse ($transactions as $tx)
            <div class="flex items-center justify-between px-5 py-3.5 text-sm">
                <div>
                    <p class="font-medium">{{ $tx->description }}</p>
                    <p class="text-xs text-muted">{{ $tx->created_at->format('M j, Y') }} · {{ str_replace('_', ' ', $tx->type) }}</p>
                </div>
                <span class="font-bold {{ $tx->amount >= 0 ? 'text-emerald-600' : 'text-rose-500' }}">
                    {{ $tx->amount >= 0 ? '+' : '−' }}${{ number_format(abs($tx->amount), 2) }}
                </span>
            </div>
        @empty
            <p class="px-5 py-8 text-center text-sm text-muted">No credit activity yet — earn cashback on your first delivered order.</p>
        @endforelse
    </div>
    @if ($transactions->hasPages())<div class="mt-4">{{ $transactions->links() }}</div>@endif
</div>
@endsection
