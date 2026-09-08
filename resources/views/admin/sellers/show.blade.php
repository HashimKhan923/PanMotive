@extends('layouts.admin')

@section('title', $profile->business_name)

@section('content')
<div class="max-w-3xl">
    <h1 class="text-xl font-bold mb-4">{{ $profile->business_name }}</h1>

    @if (session('success'))
        <p class="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('success') }}</p>
    @endif

    <div class="border rounded-xl p-4 space-y-2 text-sm bg-white">
        <p><strong>Owner:</strong> {{ $profile->user->name }} ({{ $profile->user->email }})</p>
        <p><strong>Business Type:</strong> {{ $profile->business_type }}</p>
        <p><strong>Status:</strong> <span class="px-2 py-1 rounded-full bg-amber-100 text-amber-700 text-xs">{{ $profile->status }}</span></p>
        <p><strong>Stripe Onboarding:</strong> {{ $profile->stripe_onboarding_complete ? 'Complete ✓' : 'Incomplete' }}</p>
        <p><strong>Applied:</strong> {{ $profile->created_at->diffForHumans() }}</p>
    </div>

    @if ($profile->status === 'pending')
        <div class="flex gap-3 mt-4">
            <form method="POST" action="{{ route('admin.sellers.approve', $profile) }}">
                @csrf
                <button class="bg-emerald-500 hover:bg-emerald-400 text-white font-semibold rounded-lg px-5 py-2.5 transition">Approve</button>
            </form>
            <button onclick="document.getElementById('reject-modal').showModal()" class="bg-red-500 hover:bg-red-400 text-white font-semibold rounded-lg px-5 py-2.5 transition">
                Reject
            </button>
        </div>

        <dialog id="reject-modal" class="rounded-xl p-6">
            <form method="POST" action="{{ route('admin.sellers.reject', $profile) }}" class="space-y-3 w-80">
                @csrf
                <textarea name="reason" placeholder="Reason for rejection..." rows="4" class="w-full rounded-lg border-slate-300" required></textarea>
                <button class="bg-red-500 text-white font-semibold rounded-lg px-4 py-2 w-full">Confirm Reject</button>
            </form>
        </dialog>
    @elseif ($profile->status === 'approved')
        <form method="POST" action="{{ route('admin.sellers.suspend', $profile) }}" class="mt-4 flex gap-2">
            @csrf
            <input name="reason" placeholder="Reason for suspension" class="flex-1 rounded-lg border-slate-300 text-sm" required>
            <button class="bg-red-500 hover:bg-red-400 text-white text-sm font-semibold rounded-lg px-4 py-2">Suspend</button>
        </form>
    @endif
</div>
@endsection
