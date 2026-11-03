@extends('layouts.admin')

@section('title', 'Alerts')

@section('content')
<x-admin.header title="Alerts" subtitle="Problems the system flagged for a person: blocked payouts, failed transfers and similar." />

@if ($alerts->isEmpty())
    <x-admin.card><x-admin.empty title="No open alerts" text="Nothing needs attention." /></x-admin.card>
@else
    <div class="space-y-3">
        @foreach ($alerts as $alert)
            <x-admin.card>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <x-admin.status value="warning" :label="ucfirst(str_replace('_', ' ', $alert->type))" />
                        <p class="mt-2 text-sm">{{ $alert->message }}</p>
                        <p class="mt-1 text-xs text-muted">{{ $alert->created_at->diffForHumans() }}</p>
                    </div>
                    <form method="POST" action="{{ route('admin.alerts.resolve', $alert) }}">
                        @csrf
                        <button class="btn-outline btn-sm">Resolve</button>
                    </form>
                </div>
            </x-admin.card>
        @endforeach
    </div>
    <div class="mt-4">{{ $alerts->links() }}</div>
@endif
@endsection
