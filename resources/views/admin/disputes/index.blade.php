@extends('layouts.admin')

@section('title', 'Disputes & Returns')

@section('content')
<div>
    <h1 class="text-xl font-bold mb-4">Disputes &amp; Returns</h1>

    @if (session('success'))
        <p class="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('success') }}</p>
    @endif
    @error('refund')
        <p class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</p>
    @enderror

    @forelse ($items as $item)
        <div class="border rounded-xl p-4 mb-3 bg-white" data-reveal>
            <div class="flex justify-between items-start">
                <div>
                    <p class="font-medium">{{ $item->product_title }}</p>
                    <p class="text-xs text-slate-500">
                        Order {{ $item->order->order_number }} ·
                        Buyer: {{ $item->order->buyer->name ?? 'Guest' }} ·
                        Seller: {{ $item->seller->sellerProfile?->business_name ?? '—' }}
                    </p>
                    <p class="text-xs text-slate-500 mt-1">Reason: {{ $item->statusHistory->last()?->note }}</p>
                    @if ($item->payout_released_at)
                        <p class="text-xs text-red-600 font-medium mt-1">⚠ Payout already released to seller — requires reversal</p>
                    @endif
                </div>
                <span class="px-2 py-1 rounded-full text-xs bg-red-100 text-red-700">{{ $item->status->label() }}</span>
            </div>

            <div class="flex gap-2 mt-3">
                @if ($item->payout_released_at)
                    <form method="POST" action="{{ route('admin.disputes.reverse-payout', $item) }}"
                          onsubmit="return confirm('Reverse seller payout and refund buyer?')">
                        @csrf
                        <input type="hidden" name="reason" value="Approved return after payout release">
                        <button class="bg-red-500 hover:bg-red-400 text-white text-sm font-semibold rounded-lg px-4 py-2 transition">
                            Reverse Payout &amp; Refund
                        </button>
                    </form>
                @else
                    <form method="POST" action="{{ route('admin.disputes.approve-return', $item) }}">
                        @csrf
                        <button class="bg-emerald-500 hover:bg-emerald-400 text-white text-sm font-semibold rounded-lg px-4 py-2 transition">
                            Approve &amp; Refund
                        </button>
                    </form>
                @endif
                <button onclick="document.getElementById('deny-{{ $item->id }}').showModal()" class="bg-slate-200 text-sm font-semibold rounded-lg px-4 py-2">
                    Deny
                </button>
                <dialog id="deny-{{ $item->id }}" class="rounded-xl p-6">
                    <form method="POST" action="{{ route('admin.disputes.deny-return', $item) }}" class="space-y-3 w-80">
                        @csrf
                        <textarea name="reason" placeholder="Reason for denial..." rows="3" class="w-full rounded-lg border-slate-300" required></textarea>
                        <button class="bg-slate-900 text-white font-semibold rounded-lg px-4 py-2 w-full">Confirm Deny</button>
                    </form>
                </dialog>
            </div>
        </div>
    @empty
        <p class="text-slate-400">No open disputes.</p>
    @endforelse

    {{ $items->links() }}
</div>
@endsection
