@extends('layouts.admin')

@section('title', 'Settings')

@section('content')
<x-admin.header title="Settings" subtitle="Changes apply straight away across the marketplace, and each one goes in the audit log. API keys stay on the server and are never shown here." />

<div class="grid gap-4 lg:grid-cols-[210px_1fr]" x-data="{ tab: (location.hash || '#switches').slice(1) }">
    <nav class="adm-scroll flex gap-1 overflow-x-auto lg:sticky lg:top-20 lg:block lg:self-start lg:space-y-0.5" aria-label="Settings groups">
        @foreach ($registry as $key => $group)
            <a href="#{{ $key }}" @click="tab = @js($key)" class="adm-nav-link whitespace-nowrap" :class="tab === @js($key) ? 'is-active' : ''">
                <x-ui.icon :name="$group['icon']" class="w-4 h-4 shrink-0" /> {{ $group['label'] }}
            </a>
        @endforeach
    </nav>

    <div>
        @foreach ($registry as $groupKey => $group)
            <form method="POST" action="{{ route('admin.settings.update') }}#{{ $groupKey }}" x-show="tab === @js($groupKey)" x-cloak x-transition.opacity.duration.200ms>
                @csrf
                <input type="hidden" name="group" value="{{ $groupKey }}">
                <x-admin.card :title="$group['label']" :subtitle="$group['hint']">
                    <div class="divide-y divide-line/60">
                        @foreach ($group['fields'] as $key => $field)
                            @php
                                $name = 'settings['.\App\Http\Controllers\Admin\SettingsController::inputName($key).']';
                                $id = 'set-'.\Illuminate\Support\Str::slug($key);
                                $value = config($key);
                            @endphp
                            <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 py-4 first:pt-0 last:pb-0">
                                <div class="min-w-0 flex-1 basis-64">
                                    <label for="{{ $id }}" class="text-[13px] font-semibold">{{ $field['label'] }}</label>
                                    @isset($field['hint'])<p class="mt-0.5 text-xs text-muted">{{ $field['hint'] }}</p>@endisset
                                </div>
                                @if ($field['type'] === 'bool')
                                    <label class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center">
                                        <input id="{{ $id }}" type="checkbox" name="{{ $name }}" value="1" @checked($value) class="peer sr-only">
                                        <span class="absolute inset-0 rounded-full bg-surface-2 ring-1 ring-line transition peer-checked:bg-accent peer-focus-visible:ring-2 peer-focus-visible:ring-accent"></span>
                                        <span class="absolute left-1 h-4 w-4 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
                                    </label>
                                @elseif ($field['type'] === 'text')
                                    <input id="{{ $id }}" name="{{ $name }}" value="{{ $value }}" maxlength="500" class="input basis-72 text-sm sm:!w-80">
                                @else
                                    <div class="flex shrink-0 items-center gap-1.5">
                                        @isset($field['prefix'])<span class="text-sm text-muted">{{ $field['prefix'] }}</span>@endisset
                                        <input id="{{ $id }}" type="number" name="{{ $name }}" value="{{ $value }}" min="{{ $field['min'] }}" max="{{ $field['max'] }}" step="{{ $field['type'] === 'int' ? 1 : 0.01 }}" required class="input !w-28 text-right text-sm tabular-nums">
                                        @isset($field['suffix'])<span class="w-10 text-xs text-muted">{{ $field['suffix'] }}</span>@endisset
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-5 flex justify-end border-t border-line/60 pt-4"><button class="btn-accent btn-sm">Save {{ strtolower($group['label']) }}</button></div>
                </x-admin.card>
            </form>
        @endforeach
    </div>
</div>
@endsection
