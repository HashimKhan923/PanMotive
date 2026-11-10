@props(['text', 'label' => 'Copy'])
{{-- Copies a value (address, tracking number, email) and briefly shows a tick. --}}
<button type="button" x-data="{ done: false }"
        x-on:click="navigator.clipboard?.writeText(@js((string) $text)).then(() => { done = true; setTimeout(() => done = false, 1600) })"
        title="{{ $label }}" :aria-label="done ? 'Copied' : @js($label)"
        {{ $attributes->class('pm-tap relative inline-grid h-7 w-7 shrink-0 place-items-center rounded-lg text-muted transition hover:bg-surface-2 hover:text-fg') }}>
    <span x-show="! done"><x-ui.icon name="copy" class="w-4 h-4" /></span>
    <span x-show="done" x-cloak class="text-emerald-600"><x-ui.icon name="check" class="w-4 h-4" /></span>
</button>
