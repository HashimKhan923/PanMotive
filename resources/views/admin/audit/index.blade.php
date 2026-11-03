@extends('layouts.admin')

@section('title', 'Audit log')

@section('content')
<x-admin.header title="Audit log" subtitle="Who did what, and when. Entries can't be edited or deleted.">
    <a href="{{ route('admin.audit.export', request()->query()) }}" class="btn-outline btn-sm"><x-ui.icon name="download" class="w-3.5 h-3.5" /> Export CSV</a>
</x-admin.header>

<x-admin.filters :search="request('q', '')" placeholder="What happened">
    <select name="causer" class="input h-9 !w-auto !py-0 text-[13px]" aria-label="Who">
        <option value="">Everyone</option>
        @foreach ($staff as $s)<option value="{{ $s->id }}" @selected((string) request('causer') === (string) $s->id)>{{ $s->name }}</option>@endforeach
    </select>
    <input type="date" name="from" value="{{ request('from') }}" class="input h-9 !w-auto !py-0 text-[13px]" aria-label="From date">
    <input type="date" name="to" value="{{ request('to') }}" class="input h-9 !w-auto !py-0 text-[13px]" aria-label="To date">
</x-admin.filters>

<x-admin.card flush>
    @if ($entries->isEmpty())
        <x-admin.empty icon="clock" title="Nothing logged here" />
    @else
        <table class="adm-table">
            <thead><tr><th>When</th><th>Who</th><th>What</th><th>On</th><th>Details</th></tr></thead>
            <tbody>
                @foreach ($entries as $e)
                    <tr>
                        <td class="whitespace-nowrap text-muted" title="{{ $e->created_at->toDateTimeString() }}">{{ $e->created_at->format('M j, g:i A') }}</td>
                        <td class="whitespace-nowrap">{{ $e->causer?->name ?? 'System' }}</td>
                        <td class="font-medium">{{ $e->description }}</td>
                        <td class="whitespace-nowrap text-muted">{{ $e->subject_type ? class_basename($e->subject_type).' #'.$e->subject_id : '—' }}</td>
                        <td class="max-w-[320px]">
                            @if ($e->properties->isNotEmpty())
                                <details><summary class="cursor-pointer text-xs font-medium text-muted hover:text-accent">Show</summary>
                                    <pre class="adm-scroll mt-1 overflow-x-auto rounded-lg bg-surface-2 p-2 text-[11px]">{{ json_encode($e->properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-admin.card>
<div class="mt-4">{{ $entries->links() }}</div>
@endsection
