@extends('layouts.admin')

@section('title', 'System health')

@section('content')
<x-admin.header title="System health" subtitle="Is everything running, and what is left to set up before launch.">
    <x-admin.action :url="route('admin.system.cache')" label="Clear caches" icon="refresh" body="Use after a settings or code problem. Nobody is signed out and nothing is lost." confirm="Clear" />
</x-admin.header>

<div class="grid gap-4 xl:grid-cols-3">
    <x-admin.card title="Live checks">
        <ul class="space-y-3 text-[13px]">
            @foreach ($checks as $c)
                <li class="flex items-start justify-between gap-3">
                    <div class="min-w-0"><p class="font-medium">{{ $c['label'] }}</p><p class="text-xs text-muted">{{ $c['detail'] }}</p></div>
                    <x-admin.status :value="$c['ok'] ? 'on' : 'failed'" :label="$c['ok'] ? 'OK' : 'Problem'" />
                </li>
            @endforeach
        </ul>
    </x-admin.card>

    <x-admin.card title="Connected services" subtitle="Keys live on the server and are never shown here.">
        <ul class="space-y-3 text-[13px]">
            @foreach ($integrations as [$label, $ok, $hint])
                <li class="flex items-start justify-between gap-3">
                    <div class="min-w-0"><p class="font-medium">{{ $label }}</p><p class="text-xs text-muted">{{ $hint }}</p></div>
                    <x-admin.status :value="$ok ? 'on' : 'off'" :label="$ok ? 'Set' : 'Missing'" />
                </li>
            @endforeach
        </ul>
    </x-admin.card>

    <x-admin.card title="Before you launch">
        <ul class="space-y-3 text-[13px]">
            @foreach ($launch as [$label, $ok, $hint])
                <li class="flex items-start justify-between gap-3">
                    <div class="min-w-0"><p class="font-medium">{{ $label }}</p>@unless ($ok)<p class="text-xs text-muted">{{ $hint }}</p>@endunless</div>
                    <x-admin.status :value="$ok ? 'on' : 'warning'" :label="$ok ? 'Done' : 'To do'" />
                </li>
            @endforeach
        </ul>
    </x-admin.card>
</div>

<x-admin.card title="Failed background jobs" :subtitle="number_format($failedCount).' in total'" flush class="mt-4">
    @if ($failed->isEmpty())
        <x-admin.empty title="No failed jobs" text="Nothing has failed so far." />
    @else
        <table class="adm-table">
            <thead><tr><th>Job</th><th>Error</th><th>Failed</th><th></th></tr></thead>
            <tbody>
                @foreach ($failed as $job)
                    <tr>
                        <td class="font-medium">{{ $job->name }}</td>
                        <td class="max-w-[420px] truncate text-xs text-muted" title="{{ $job->error }}">{{ $job->error }}</td>
                        <td class="whitespace-nowrap text-muted">{{ \Illuminate\Support\Carbon::parse($job->failed_at)->diffForHumans() }}</td>
                        <td class="whitespace-nowrap text-right">
                            <x-admin.action :url="route('admin.system.retry', $job->uuid)" label="Retry" size="xs" :title="'Retry '.$job->name" />
                            <x-admin.action :url="route('admin.system.forget', $job->uuid)" method="DELETE" label="Delete" tone="danger" size="xs" :title="'Delete '.$job->name" body="The job will not run again." />
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-admin.card>

<x-admin.card title="Environment" class="mt-4">
    <dl class="grid grid-cols-2 gap-4 text-[13px] sm:grid-cols-4">
        @foreach ($env as $label => $value)
            <div><dt class="text-xs text-muted">{{ $label }}</dt><dd class="font-medium">{{ $value }}</dd></div>
        @endforeach
    </dl>
</x-admin.card>
@endsection
