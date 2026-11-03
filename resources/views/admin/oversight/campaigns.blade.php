@extends('layouts.admin')

@section('title', 'Seller campaigns')

@section('content')
<x-admin.header title="Seller campaigns" subtitle="Email and text campaigns sellers send to their customers. Stop anything spammy before it goes out." />

<x-admin.tabs :tabs="$tabs" :current="$status" />
<x-admin.filters :search="request('q', '')" placeholder="Campaign name or subject">@if ($status)<input type="hidden" name="status" value="{{ $status }}">@endif</x-admin.filters>

<x-admin.card flush class="mb-4">
    @if ($campaigns->isEmpty())
        <x-admin.empty icon="mail" title="No campaigns here" />
    @else
        <table class="adm-table">
            <thead><tr><th>Campaign</th><th>Store</th><th>Channel</th><th>Status</th><th>When</th><th class="num">Reached</th><th></th></tr></thead>
            <tbody>
                @foreach ($campaigns as $c)
                    <tr>
                        <td class="max-w-[300px]"><p class="truncate font-medium">{{ $c->name }}</p><p class="truncate text-xs text-muted">{{ $c->subject }}</p></td>
                        <td class="whitespace-nowrap text-muted">{{ $c->seller?->sellerProfile?->business_name ?? '—' }}</td>
                        <td class="uppercase text-muted">{{ $c->channel }}</td>
                        <td><x-admin.status :value="$c->status === 'sent' ? 'completed' : $c->status" :label="ucfirst($c->status)" /></td>
                        <td class="whitespace-nowrap text-muted">{{ ($c->sent_at ?? $c->scheduled_at ?? $c->created_at)->format('M j, Y') }}</td>
                        <td class="num">{{ number_format((int) ($c->stats['recipients'] ?? 0)) }}</td>
                        <td class="text-right">
                            @if ($c->status === 'scheduled')
                                <x-admin.action :url="route('admin.campaigns.stop', $c)" label="Stop" tone="danger" size="xs" reason :title="'Stop '.$c->name" body="It won't send, and goes back to the seller's drafts." />
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-admin.card>
<div class="mb-4">{{ $campaigns->links() }}</div>

<x-admin.card title="Running automations" subtitle="Emails sellers have set to send by themselves." flush>
    @if ($automations->isEmpty())
        <x-admin.empty icon="refresh" title="No automations running" class="!py-8" />
    @else
        <table class="adm-table">
            <thead><tr><th>Automation</th><th>Store</th><th>Channel</th><th>Last run</th><th></th></tr></thead>
            <tbody>
                @foreach ($automations as $a)
                    <tr>
                        <td class="max-w-[320px]"><p class="truncate font-medium">{{ ucfirst(str_replace('_', ' ', $a->trigger)) }}</p><p class="truncate text-xs text-muted">{{ $a->subject }}</p></td>
                        <td class="whitespace-nowrap text-muted">{{ $a->seller?->sellerProfile?->business_name ?? '—' }}</td>
                        <td class="uppercase text-muted">{{ $a->channel }}</td>
                        <td class="whitespace-nowrap text-muted">{{ $a->last_run_at?->diffForHumans() ?? 'Not yet' }}</td>
                        <td class="text-right"><x-admin.action :url="route('admin.automations.stop', $a)" label="Switch off" tone="danger" size="xs" reason title="Switch off this automation" /></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</x-admin.card>
@endsection
