@props(['title', 'subtitle' => null, 'back' => null])
<div {{ $attributes->class('mb-6 flex flex-wrap items-end justify-between gap-4') }}>
    <div class="min-w-0">
        @if ($back)
            <a href="{{ $back }}" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-muted hover:text-accent"><x-ui.icon name="arrow-left" class="w-4 h-4" /> Back</a>
        @endif
        <h1 class="truncate text-xl font-bold tracking-tight sm:text-2xl">{{ $title }}</h1>
        @if ($subtitle)<p class="mt-1 max-w-3xl text-sm text-muted">{{ $subtitle }}</p>@endif
    </div>
    @if (trim($slot))
        <div class="flex flex-wrap items-center gap-2">{{ $slot }}</div>
    @endif
</div>
