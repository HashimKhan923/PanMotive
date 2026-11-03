{{-- A GET filter row. `search` adds the text box; selects and dates go in the slot. --}}
@props(['search' => null, 'placeholder' => 'Search'])
<form method="GET" {{ $attributes->class('mb-4 flex flex-wrap items-center gap-2') }}>
    @if ($search !== null)
        <div class="relative min-w-[220px] flex-1 sm:max-w-sm">
            <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 w-4 h-4 -translate-y-1/2 text-muted" />
            <input type="search" name="q" value="{{ $search }}" placeholder="{{ $placeholder }}" aria-label="{{ $placeholder }}" class="input h-9 !py-0 pl-9 text-[13px]">
        </div>
    @endif
    {{ $slot }}
    <button class="h-9 rounded-lg border border-line px-3.5 text-[13px] font-semibold transition hover:border-accent/50 hover:text-accent">Apply</button>
    @if (request()->except('page'))
        <a href="{{ url()->current() }}" class="h-9 rounded-lg px-2 text-[13px] font-medium leading-9 text-muted hover:text-fg">Clear</a>
    @endif
</form>
