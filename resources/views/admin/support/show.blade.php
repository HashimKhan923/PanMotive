@extends('layouts.admin')

@section('title', $ticket->ticket_number)

@section('content')
<div class="max-w-3xl">
    <x-admin.header :title="$ticket->subject" :back="route('admin.support.index')"
        :subtitle="$ticket->ticket_number.' · '.($ticket->seller?->sellerProfile?->business_name ?? $ticket->seller?->name).' · '.(\App\Models\SupportTicket::CATEGORIES[$ticket->category] ?? $ticket->category).' · '.$ticket->statusLabel().($ticket->orderItem ? ' · Order '.$ticket->orderItem->order?->order_number : '')" />

    <div class="space-y-3">
        @foreach ($ticket->messages as $m)
            <div @class(['adm-card p-4 text-sm', 'ml-8 !border-accent/30' => $m->author_role === 'support', 'mr-8' => $m->author_role !== 'support'])>
                <p class="text-xs font-semibold text-muted">{{ $m->author_role === 'support' ? 'Support · '.($m->user?->name ?? 'agent') : ($m->user?->name ?? 'Seller') }} · {{ $m->created_at->format('M j, Y g:i A') }}</p>
                <p class="mt-1 whitespace-pre-line">{{ $m->body }}</p>
            </div>
        @endforeach
    </div>

    <form method="POST" action="{{ route('admin.support.reply', $ticket) }}" class="adm-card mt-4 p-4">
        @csrf
        <label class="label" for="body">Reply to seller</label>
        <textarea id="body" name="body" rows="4" class="input text-sm" required></textarea>
        <div class="mt-3 flex justify-between gap-2">
            <button formaction="{{ route('admin.support.resolve', $ticket) }}" formnovalidate class="btn-outline btn-sm">Mark resolved</button>
            <button class="btn-accent btn-sm">Send reply</button>
        </div>
    </form>
</div>
@endsection
