@extends('layouts.admin')

@section('title', 'Seller support')

@section('content')
<x-admin.header title="Seller support" subtitle="Questions from sellers. High priority first." />

<x-admin.tabs :current="$status" :tabs="[
    'open' => ['Waiting on us', $counts['open'] ?? 0],
    'answered' => ['Waiting on seller', $counts['answered'] ?? 0],
    'resolved' => ['Resolved', $counts['resolved'] ?? 0],
    'all' => ['All', $counts->sum()],
]" />

@if ($tickets->isEmpty())
    <x-admin.card><x-admin.empty icon="message" title="No tickets here" /></x-admin.card>
@else
    <div class="space-y-2">
        @foreach ($tickets as $t)
            <a href="{{ route('admin.support.show', $t) }}" class="adm-card flex items-start justify-between gap-4 p-4">
                <div class="min-w-0">
                    <p class="flex items-center gap-2 truncate text-sm font-semibold">
                        @if ($t->admin_unread)<span class="h-2 w-2 shrink-0 rounded-full bg-accent" title="Unread"></span>@endif
                        {{ $t->subject }}
                    </p>
                    <p class="mt-1 truncate text-xs text-muted">{{ $t->ticket_number }} · {{ $t->seller?->sellerProfile?->business_name ?? $t->seller?->name }} · {{ \App\Models\SupportTicket::CATEGORIES[$t->category] ?? $t->category }}</p>
                </div>
                <div class="shrink-0 text-right text-xs text-muted">
                    @if ($t->priority === 'high')<x-admin.status value="escalated" label="High" />@endif
                    <p class="mt-1">{{ $t->last_reply_at?->diffForHumans() }}</p>
                </div>
            </a>
        @endforeach
    </div>
    <div class="mt-4">{{ $tickets->links() }}</div>
@endif
@endsection
