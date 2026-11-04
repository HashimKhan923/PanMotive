@props(['icon' => 'check', 'title' => 'Nothing here', 'text' => null])
<div {{ $attributes->class('grid place-items-center px-6 py-12 text-center') }}>
    <span class="grid h-11 w-11 place-items-center rounded-2xl bg-surface-2 text-fg"><x-ui.icon :name="$icon" class="w-6 h-6" /></span>
    <p class="mt-3 text-sm font-semibold">{{ $title }}</p>
    @if ($text)<p class="mt-1 max-w-sm text-xs text-muted">{{ $text }}</p>@endif
</div>
