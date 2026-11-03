@extends('layouts.admin')

@section('title', 'Broadcast email')

@section('content')
<x-admin.header title="Broadcast email" subtitle="Email from Pan Motive to your buyers and sellers. Save a draft, send yourself a test, then send it." />

<div class="grid gap-4 xl:grid-cols-3">
    <div class="xl:col-span-2">
        <x-admin.card title="Sent and drafts" flush>
            @if ($broadcasts->isEmpty())
                <x-admin.empty icon="mail" title="No broadcasts yet" text="Write your first one on this page." />
            @else
                <table class="adm-table">
                    <thead><tr><th>Subject</th><th>To</th><th>Status</th><th class="num">Sent</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($broadcasts as $b)
                            @php($count = $reach[$b->audience][$b->kind] ?? 0)
                            <tr>
                                <td class="max-w-[300px]">
                                    <p class="truncate font-medium">{{ $b->subject }}</p>
                                    <p class="text-xs text-muted">{{ $b->kind === 'marketing' ? 'Marketing' : 'Important notice' }} · {{ $b->author?->name }} · {{ ($b->sent_at ?? $b->created_at)->format('M j, Y') }}</p>
                                </td>
                                <td class="whitespace-nowrap text-muted">{{ $audiences[$b->audience] ?? $b->audience }}</td>
                                <td><x-admin.status :value="$b->status === 'sent' ? 'completed' : ($b->status === 'sending' ? 'info' : ($b->status === 'queued' ? 'scheduled' : $b->status))" :label="ucfirst($b->status)" /></td>
                                <td class="num">{{ number_format($b->sent_count) }}@if ($b->failed_count) <span class="text-rose-500">({{ $b->failed_count }} failed)</span>@endif</td>
                                <td class="whitespace-nowrap text-right">
                                    @if ($b->status === 'draft')
                                        <x-admin.action :url="route('admin.broadcasts.test', $b)" label="Test" size="xs" :title="'Send a test of '.$b->subject" :body="'Goes only to '.auth()->user()->email.'.'" confirm="Send test" />
                                        <x-admin.action :url="route('admin.broadcasts.send', $b)" label="Send" size="xs" tone="danger" :title="'Send to '.number_format($count).' people?'" body="This can't be stopped or undone once it starts." confirm="Send now">
                                            <div><label class="label">Type {{ $count }} to confirm</label><input name="confirm" inputmode="numeric" autocomplete="off" class="input text-sm" required></div>
                                        </x-admin.action>
                                        <x-admin.action :url="route('admin.broadcasts.destroy', $b)" method="DELETE" label="Delete" size="xs" title="Delete this draft" />
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-admin.card>
        <div class="mt-4">{{ $broadcasts->links() }}</div>
    </div>

    <x-admin.card title="New broadcast">
        <form method="POST" action="{{ route('admin.broadcasts.store') }}" class="space-y-3" x-data="{ audience: 'buyers', kind: 'marketing', reach: @js($reach) }">
            @csrf
            <div class="grid grid-cols-2 gap-2">
                <div><label class="label" for="b-aud">Send to</label>
                    <select id="b-aud" name="audience" x-model="audience" class="input text-sm">
                        @foreach ($audiences as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                    </select></div>
                <div><label class="label" for="b-kind">Type</label>
                    <select id="b-kind" name="kind" x-model="kind" class="input text-sm">
                        <option value="marketing">Marketing</option>
                        <option value="notice">Important notice</option>
                    </select></div>
            </div>
            <p class="rounded-lg bg-surface-2 px-3 py-2 text-xs text-muted">
                Reaches <strong class="text-fg tabular-nums" x-text="reach[audience][kind]"></strong> people.
                <span x-show="kind === 'marketing'">Includes an unsubscribe link and skips anyone who unsubscribed.</span>
                <span x-show="kind === 'notice'">Goes to everyone, so by US law it must not promote anything: use it only for policy, security or service news.</span>
            </p>
            <div><label class="label" for="b-subj">Subject</label><input id="b-subj" name="subject" value="{{ old('subject') }}" maxlength="150" class="input text-sm" required></div>
            <div><label class="label" for="b-prev">Preview text <span class="font-normal text-muted">(optional)</span></label><input id="b-prev" name="preview" value="{{ old('preview') }}" maxlength="150" class="input text-sm"></div>
            <div>
                <label class="label" for="b-body">Message</label>
                <input id="b-body" type="hidden" name="body" value="{{ old('body') }}">
                <trix-editor input="b-body" class="prose-pm min-h-[180px] rounded-xl border border-line bg-surface p-3 text-sm"></trix-editor>
            </div>
            <button class="btn-accent btn-sm w-full">Save draft</button>
            <p class="text-xs text-muted">Sending needs the background queue running on the server.</p>
        </form>
    </x-admin.card>
</div>
@endsection
