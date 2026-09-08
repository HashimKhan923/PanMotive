<div class="mt-1 rounded-lg border border-slate-200 p-3">
    <input wire:model.live.debounce.300ms="search" placeholder="Search by make or model…"
           class="w-full rounded-lg border-slate-300 text-sm">

    @if ($results->isNotEmpty())
        <ul class="mt-2 max-h-48 overflow-y-auto divide-y divide-slate-100 text-sm">
            @foreach ($results as $vehicle)
                <li>
                    <button type="button" wire:click="toggle({{ $vehicle->id }})"
                            class="flex w-full items-center justify-between px-2 py-1.5 hover:bg-slate-50">
                        <span>{{ $vehicle->label() }}</span>
                        <span class="text-xs {{ in_array($vehicle->id, $selected, true) ? 'text-green-600' : 'text-slate-400' }}">
                            {{ in_array($vehicle->id, $selected, true) ? 'Added' : 'Add' }}
                        </span>
                    </button>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($chosen->isNotEmpty())
        <div class="mt-3 flex flex-wrap gap-2">
            @foreach ($chosen as $vehicle)
                <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs">
                    {{ $vehicle->label() }}
                    <button type="button" wire:click="toggle({{ $vehicle->id }})" class="text-slate-500 hover:text-slate-900">&times;</button>
                </span>
            @endforeach
        </div>
    @else
        <p class="mt-2 text-xs text-slate-400">No vehicles assigned yet.</p>
    @endif
</div>
