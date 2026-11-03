@extends('layouts.storefront')
@section('title', 'Affiliate dashboard')

@section('content')
<div class="container-x max-w-2xl py-10">
    <h1 class="text-2xl font-bold">Affiliate dashboard</h1>
    <p class="mt-1 text-sm text-muted">Your links and earnings across stores. Commission is paid to your Pan Motive credit once orders are delivered.</p>

    <div class="mt-6 space-y-4">
        @forelse ($affiliates as $a)
            <div class="card p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-semibold">{{ $a->program->seller->sellerProfile?->business_name ?? 'Store' }}</p>
                        <p class="text-xs text-muted">{{ rtrim(rtrim(number_format($a->program->commission_pct, 2), '0'), '.') }}% commission · {{ ucfirst($a->status) }}</p>
                    </div>
                    <div class="text-right text-sm">
                        <p class="font-bold">${{ number_format($a->earnings, 2) }} earned</p>
                        <p class="text-xs text-muted">${{ number_format($a->paid_out, 2) }} paid</p>
                    </div>
                </div>
                @if ($a->status === 'approved')
                    <div class="mt-3 flex items-center gap-2 border-t border-line pt-3" x-data="{ c: false }">
                        <code class="flex-1 overflow-x-auto rounded bg-surface-2 px-2 py-1 text-xs">{{ url('/a/'.$a->code) }}</code>
                        <button @click="navigator.clipboard.writeText('{{ url('/a/'.$a->code) }}'); c = true; setTimeout(() => c = false, 1500)" class="btn-outline btn-sm" x-text="c ? 'Copied' : 'Copy'"></button>
                    </div>
                    <p class="mt-2 text-xs text-muted">{{ number_format($a->clicks) }} clicks · {{ $a->conversions }} orders</p>
                @elseif ($a->status === 'pending')
                    <p class="mt-3 border-t border-line pt-3 text-sm text-muted">Waiting on the seller to approve you.</p>
                @endif
            </div>
        @empty
            <div class="card grid place-items-center p-16 text-center">
                <x-ui.icon name="sparkles" class="w-10 h-10 text-muted" />
                <p class="mt-3 font-semibold">You're not an affiliate yet</p>
                <p class="mt-1 text-sm text-muted">Visit a store with an affiliate program and apply from its page.</p>
            </div>
        @endforelse
    </div>
</div>
@endsection
