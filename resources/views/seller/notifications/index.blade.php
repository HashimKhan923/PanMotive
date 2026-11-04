@extends('layouts.seller')

@section('title', 'Notifications')

@section('content')
<div class="max-w-2xl">
    <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold">Notifications</h1>
        @if (auth()->user()->unreadNotifications->count())
            <form method="POST" action="{{ route('seller.notifications.read') }}">
                @csrf
                <button class="btn-ghost btn-sm">Mark all read</button>
            </form>
        @endif
    </div>

    <div class="mt-6 space-y-2">
        @forelse ($notifications as $note)
            @php($d = $note->data)
            <a href="{{ $d['url'] ?? '#' }}" wire:key="n-{{ $note->id }}"
               class="block rounded-xl border p-4 transition hover:border-accent
                      {{ $note->read_at ? 'border-line bg-surface' : 'border-accent/30 bg-accent/5' }}">
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-surface-2">
                        <x-ui.icon :name="match($d['type'] ?? '') { 'order' => 'truck', 'return' => 'refresh', 'payout' => 'dollar', 'review' => 'star', 'message' => 'mail', default => 'bell' }" class="w-4 h-4" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium">{{ $d['title'] ?? 'Notification' }}</p>
                        @if (!empty($d['body'])) <p class="mt-0.5 text-sm text-muted">{{ $d['body'] }}</p> @endif
                        <p class="mt-1 text-xs text-muted">{{ $note->created_at->diffForHumans() }}</p>
                    </div>
                    @unless ($note->read_at) <span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-accent"></span> @endunless
                </div>
            </a>
        @empty
            <div class="card grid place-items-center p-16 text-center">
                <x-ui.icon name="bell" class="w-12 h-12 text-muted" />
                <p class="mt-3 font-semibold">Nothing yet</p>
                <p class="mt-1 text-sm text-muted">Order, payout, review and message alerts show up here.</p>
            </div>
        @endforelse
    </div>

    <div class="mt-4">{{ $notifications->links() }}</div>
</div>
@endsection
