{{-- A button that opens a confirmation dialog, because admin actions change real accounts and money.
     Put extra form fields in the slot. With `reason`, a written reason is required (it goes to the audit log). --}}
@props([
    'url',
    'label',
    'title' => null,
    'body' => null,
    'method' => 'POST',
    'reason' => false,
    'reasonName' => 'reason',
    'reasonLabel' => 'Reason',
    'tone' => 'default',     // default | danger | primary
    'size' => 'sm',
    'confirm' => null,
    'icon' => null,
])
@php
    $btn = match ($tone) {
        'danger' => 'border-rose-500/40 text-rose-600 hover:bg-rose-500/10 dark:text-rose-400',
        'primary' => 'border-transparent bg-accent text-accent-fg hover:opacity-90',
        default => 'border-line text-fg hover:border-accent/50 hover:text-accent',
    };
    $confirmBtn = $tone === 'danger' ? 'bg-rose-600 text-white hover:bg-rose-500' : 'bg-accent text-accent-fg hover:opacity-90';
@endphp
<div x-data="{ open: false }" class="inline-block" {{ $attributes->only('wire:key') }}>
    <button type="button" @click="open = true" {{ $attributes->except('wire:key')->class("inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border font-semibold transition ".($size === 'xs' ? 'h-7 px-2.5 text-xs' : 'h-9 px-3.5 text-[13px]')." {$btn}") }}>
        @if ($icon)<x-ui.icon :name="$icon" class="w-4 h-4" />@endif
        {{ $label }}
    </button>

    <template x-teleport="body">
        <div x-show="open" x-cloak @keydown.escape.window="open = false" class="fixed inset-0 z-[85] grid place-items-center p-4" role="dialog" aria-modal="true">
            <div x-show="open" x-transition.opacity class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="open = false"></div>
            <form method="POST" action="{{ $url }}" x-show="open"
                  x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="opacity-0 scale-95 translate-y-2" x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                  class="relative w-full max-w-md rounded-2xl border border-line bg-surface p-6 text-left text-fg shadow-2xl">
                @csrf
                @if (strtoupper($method) !== 'POST') @method($method) @endif
                <h3 class="text-base font-bold">{{ $title ?? $label }}</h3>
                @if ($body)<p class="mt-1.5 text-sm text-muted">{{ $body }}</p>@endif

                @if (trim($slot))
                    <div class="mt-4 space-y-3">{{ $slot }}</div>
                @endif

                @if ($reason)
                    <div class="mt-4">
                        <label class="label">{{ $reasonLabel }}</label>
                        <textarea name="{{ $reasonName }}" rows="3" required maxlength="1000" class="input text-sm"></textarea>
                    </div>
                @endif

                <div class="mt-5 flex justify-end gap-2">
                    <button type="button" @click="open = false" class="h-9 rounded-lg px-3.5 text-[13px] font-semibold text-muted hover:bg-surface-2">Cancel</button>
                    <button class="h-9 rounded-lg px-4 text-[13px] font-semibold transition {{ $confirmBtn }}">{{ $confirm ?? $label }}</button>
                </div>
            </form>
        </div>
    </template>
</div>
