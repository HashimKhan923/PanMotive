@extends('layouts.admin')

@section('title', 'Emails')

@section('content')
<x-admin.header title="Emails" subtitle="Every email Panmotive sends, exactly as people receive it. Pick one, preview it, and use “Edit words” to change what it says." />

@unless ($sending)
    <div class="mb-4 flex items-start gap-3 rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-[13px]">
        <x-ui.icon name="alert" class="mt-0.5 h-5 w-5 shrink-0 text-amber-600" />
        <p><span class="font-semibold">Emails are not being delivered yet.</span> They are written to the server's log file. Connect an email service (Postmark, Resend or Amazon SES) in the server settings to start sending.</p>
    </div>
@endunless

<div class="grid gap-4 xl:grid-cols-3"
     x-data="{ key: @js(array_key_first(\App\Support\EmailPreviews::catalog())), base: @js(route('admin.emails.preview', 'emailkey')),
               editBase: @js(route('admin.emails.edit', 'emailkey')), editable: @js(array_keys(\App\Support\EmailTemplates::defaults())) }">
    <div class="space-y-4">
        <x-admin.card title="Sender settings" subtitle="Set on the server. Change them there if the addresses change.">
            <dl class="space-y-3 text-[13px]">
                @foreach ($settings + ['Postal address in footer' => $postal ?? 'Not set yet'] as $label => $value)
                    <div>
                        <dt class="text-xs text-muted">{{ $label }}</dt>
                        <dd class="mt-0.5 break-words font-medium">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-admin.card>

        @foreach ($catalog as $group => $emails)
            <x-admin.card :title="$group" flush>
                <ul class="divide-y divide-line/60">
                    @foreach ($emails as $key => $email)
                        <li>
                            <button type="button" @click="key = @js($key)"
                                    :class="key === @js($key) ? 'bg-accent/10' : 'hover:bg-surface-2'"
                                    class="flex w-full items-start gap-3 px-5 py-3 text-left transition">
                                <x-ui.icon name="mail" class="mt-0.5 h-4 w-4 shrink-0 text-muted" />
                                <span class="min-w-0">
                                    <span class="block text-[13px] font-medium" :class="key === @js($key) && 'text-accent'">{{ $email['label'] }}
                                        @if (in_array($key, $edited, true))<span class="ml-1 rounded-full bg-amber-500/15 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-400">Edited</span>@endif
                                    </span>
                                    <span class="block text-xs text-muted">{{ $email['when'] }}</span>
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </x-admin.card>
        @endforeach
    </div>

    <div class="xl:col-span-2">
        <x-admin.card title="Preview" subtitle="Desktop width. Email apps on phones show the same layout, stacked.">
            <x-slot:actions>
                <a x-show="editable.includes(key)" :href="editBase.replace('emailkey', key)" class="btn-outline btn-sm"><x-ui.icon name="cog" class="h-4 w-4" /> Edit words</a>
                <a :href="base.replace('emailkey', key)" target="_blank" rel="noopener" class="btn-ghost btn-sm"><x-ui.icon name="external" class="h-4 w-4" /> Open</a>
                <form method="POST" :action="base.replace('emailkey', key).replace('/preview', '/test')">
                    @csrf
                    <button class="btn-accent btn-sm"><x-ui.icon name="mail" class="h-4 w-4" /> Send me a test</button>
                </form>
            </x-slot:actions>
            <iframe :src="base.replace('emailkey', key)" title="Email preview" class="h-[1100px] w-full rounded-lg bg-[#F5F6F8] ring-1 ring-line" loading="lazy"></iframe>
            <p class="mt-3 text-xs text-muted">The test goes to {{ auth()->user()->email }}.</p>
        </x-admin.card>
    </div>
</div>
@endsection
