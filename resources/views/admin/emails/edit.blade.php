@extends('layouts.admin')

@section('title', 'Edit email: '.$info['label'])

@section('content')
<x-admin.header :title="'Edit email: '.$info['label']" :subtitle="$info['when']">
    <a href="{{ route('admin.emails.index') }}" class="btn-ghost btn-sm"><x-ui.icon name="arrow-left" class="h-4 w-4" /> All emails</a>
</x-admin.header>

<div class="grid gap-4 xl:grid-cols-5"
     x-data="{
        field: null,
        insert(tag) {
            const el = this.field || this.$refs.intro;
            const s = el.selectionStart ?? el.value.length, e = el.selectionEnd ?? el.value.length;
            el.value = el.value.slice(0, s) + tag + el.value.slice(e);
            el.focus(); el.selectionStart = el.selectionEnd = s + tag.length;
            this.refresh();
        },
        refresh() { clearTimeout(this.t); this.t = setTimeout(() => this.$refs.previewForm.requestSubmit(), 500); },
     }"
     x-init="$nextTick(() => $refs.previewForm.requestSubmit())">

    <div class="space-y-4 xl:col-span-2">
        <x-admin.card title="Words in this email" subtitle="Order details, totals, photos and links are added automatically. Only the words change.">
            <form id="email-edit" method="POST" action="{{ route('admin.emails.update', $key) }}" class="space-y-4" @input="refresh()" @focusin="if ($event.target.matches('input,textarea')) field = $event.target">
                @csrf
                @method('PUT')
                @foreach ([
                    'subject' => ['Subject', 'input', 'The title people see in their inbox.'],
                    'preheader' => ['Preview line', 'input', 'Grey text shown after the subject in most inboxes.'],
                    'greeting' => ['Greeting', 'input', 'The first line, e.g. “Hi {first_name},”.'],
                    'intro' => ['Main message', 'textarea', 'Shown above the order details. Leave an empty line between paragraphs.'],
                    'button' => ['Button text', 'input', 'The red button. Keep it short.'],
                    'outro' => ['Closing message', 'textarea', 'Shown after the button.'],
                ] as $name => [$label, $type, $hint])
                    @continue($name === 'button' && $default['button'] === '' && $values['button'] === '')
                    <div>
                        <label class="label" for="f-{{ $name }}">{{ $label }}</label>
                        @if ($type === 'textarea')
                            <textarea id="f-{{ $name }}" name="{{ $name }}" rows="4" class="input text-sm" @if ($name === 'intro') x-ref="intro" @endif>{{ $values[$name] }}</textarea>
                        @else
                            <input id="f-{{ $name }}" name="{{ $name }}" value="{{ $values[$name] }}" class="input text-sm" @if ($name === 'subject') required @endif>
                        @endif
                        <p class="mt-1 text-xs text-muted">{{ $hint }}</p>
                        @error($name)<p class="mt-1 text-xs font-medium text-rose-600">{{ $message }}</p>@enderror
                    </div>
                @endforeach

                <div class="flex flex-wrap items-center gap-2 border-t border-line/60 pt-4">
                    <button class="btn-accent btn-sm"><x-ui.icon name="check" class="h-4 w-4" /> Save</button>
                    @if ($template)
                        <span class="text-xs text-muted">Edited {{ $template->updated_at->diffForHumans() }}{{ $template->editor ? ' by '.$template->editor->name : '' }}</span>
                    @else
                        <span class="text-xs text-muted">Using the default wording.</span>
                    @endif
                </div>
            </form>
            @if ($template)
                <div class="mt-3">
                    <x-admin.action :url="route('admin.emails.reset', $key)" method="DELETE" label="Reset to default" tone="danger" size="xs"
                                    title="Reset this email?" body="Your changes to this email are removed and the default wording comes back." confirm="Reset" />
                </div>
            @endif
        </x-admin.card>

        <x-admin.card title="Placeholders" subtitle="Click one to add it where your cursor is. They’re replaced with real details when the email is sent.">
            <ul class="space-y-2 text-[13px]">
                @foreach ($default['vars'] as $var => $about)
                    <li class="flex items-center justify-between gap-3">
                        <button type="button" @click="insert('{{ '{'.$var.'}' }}')" class="rounded-md bg-surface-2 px-2 py-1 font-mono text-xs hover:bg-accent/15">{{ '{'.$var.'}' }}</button>
                        <span class="text-right text-xs text-muted">{{ $about }}</span>
                    </li>
                @endforeach
            </ul>
            @if (! empty($default['note']))
                <p class="mt-3 text-xs text-muted">{{ $default['note'] }}</p>
            @endif
        </x-admin.card>
    </div>

    <div class="xl:col-span-3">
        <x-admin.card title="Live preview" subtitle="Updates as you type, with sample details. Nothing is sent or saved until you press Save.">
            <x-slot:actions>
                <form method="POST" action="{{ route('admin.emails.test', $key) }}">
                    @csrf
                    <button class="btn-ghost btn-sm" title="Sends the saved version"><x-ui.icon name="mail" class="h-4 w-4" /> Send me a test</button>
                </form>
            </x-slot:actions>
            {{-- The preview form copies the editor's fields and posts them into the frame. --}}
            <form x-ref="previewForm" method="POST" action="{{ route('admin.emails.preview', $key) }}" target="email-preview" class="hidden"
                  @submit="$el.querySelectorAll('[data-copy]').forEach(n => n.remove());
                           new FormData(document.getElementById('email-edit')).forEach((v, k) => { if (k === '_method') return; const i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = v; i.dataset.copy = 1; $el.appendChild(i); });">
            </form>
            <iframe name="email-preview" title="Email preview" class="h-[1100px] w-full rounded-lg bg-[#F5F6F8] ring-1 ring-line"></iframe>
            <p class="mt-3 text-xs text-muted">“Send me a test” sends the saved version to {{ auth()->user()->email }}.</p>
        </x-admin.card>
    </div>
</div>
@endsection
