<div class="rounded-xl border border-line p-3">
    <div class="relative">
        <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 w-4 h-4 -translate-y-1/2 text-muted" />
        <input wire:model.live.debounce.300ms="search" placeholder="Search by make or model…" class="input h-10 pl-9 text-sm">
    </div>

    @if ($results->isNotEmpty())
        <ul class="mt-2 max-h-52 divide-y divide-line overflow-y-auto rounded-lg border border-line text-sm">
            @foreach ($results as $vehicle)
                <li>
                    <button type="button" wire:click="toggle({{ $vehicle->id }})"
                            class="flex w-full items-center justify-between px-3 py-2 hover:bg-surface-2">
                        <span>{{ $vehicle->label() }}</span>
                        <span class="text-xs font-medium {{ in_array($vehicle->id, $selected, true) ? 'text-emerald-600' : 'text-accent' }}">
                            {{ in_array($vehicle->id, $selected, true) ? '✓ Added' : 'Add' }}
                        </span>
                    </button>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($chosen->isNotEmpty())
        <div class="mt-3 flex flex-wrap gap-2">
            @foreach ($chosen as $vehicle)
                <span class="inline-flex items-center gap-1.5 rounded-full bg-surface-2 px-2.5 py-1 text-xs">
                    {{ $vehicle->label() }}
                    <button type="button" wire:click="toggle({{ $vehicle->id }})" class="text-muted hover:text-rose-500">&times;</button>
                </span>
            @endforeach
        </div>
    @else
        <p class="mt-2 text-xs text-muted">No vehicles assigned yet.</p>
    @endif
</div>
