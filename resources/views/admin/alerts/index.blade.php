@extends('layouts.admin')

@section('title', 'Alerts')

@section('content')
<div>
    <h1 class="text-xl font-bold mb-4">Open alerts</h1>

    @if (session('success'))
        <p class="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('success') }}</p>
    @endif

    @forelse ($alerts as $alert)
        <div class="border rounded-xl p-4 mb-3 bg-white flex items-start justify-between gap-4">
            <div>
                <span class="rounded-full bg-red-100 px-2 py-1 text-xs text-red-700">{{ $alert->type }}</span>
                <p class="mt-2 text-sm">{{ $alert->message }}</p>
                <p class="mt-1 text-xs text-slate-400">{{ $alert->created_at->diffForHumans() }}</p>
            </div>
            <form method="POST" action="{{ route('admin.alerts.resolve', $alert) }}">
                @csrf
                <button class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm hover:bg-slate-50">Resolve</button>
            </form>
        </div>
    @empty
        <p class="text-slate-400">No open alerts.</p>
    @endforelse

    <div class="mt-4">{{ $alerts->links() }}</div>
</div>
@endsection
