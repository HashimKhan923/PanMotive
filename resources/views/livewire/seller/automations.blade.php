<div class="max-w-4xl">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">Automations</h1>
            <p class="mt-1 text-sm text-muted">Set-and-forget emails that fire on buyer behaviour. Runs every hour.</p>
        </div>
        <button wire:click="runNow" class="btn-outline btn-sm">Run now</button>
    </div>

    <div class="mt-6 space-y-3">
        @foreach ($rows as $row)
            <div class="card p-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="font-semibold">{{ $row['label'] }}</span>
                            <span class="pill {{ $row['active'] ? '!bg-emerald-500/15 !text-emerald-600' : '!bg-surface-2 !text-muted' }}">
                                {{ $row['active'] ? 'On' : 'Off' }}
                            </span>
                        </div>
                        <p class="mt-1 text-xs text-muted">
                            {{ number_format($row['stats']['sent'] ?? 0) }} sent ·
                            {{ number_format($row['stats']['opened'] ?? 0) }} opened ·
                            {{ number_format($row['stats']['clicked'] ?? 0) }} clicked ·
                            {{ number_format($row['stats']['orders'] ?? 0) }} orders ·
                            ${{ number_format($row['stats']['revenue'] ?? 0, 2) }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button wire:click="toggle('{{ $row['trigger'] }}')" class="btn-ghost btn-sm">{{ $row['active'] ? 'Pause' : 'Activate' }}</button>
                        <button wire:click="edit('{{ $row['trigger'] }}')" class="btn-outline btn-sm">Edit</button>
                    </div>
                </div>

                @if ($editing === $row['trigger'])
                    <form wire:submit="save" class="mt-5 space-y-4 border-t border-line pt-5">
                        <label class="flex items-center gap-3 text-sm">
                            <input type="checkbox" wire:model="isActive" class="rounded border-line text-accent focus:ring-accent">
                            <span class="font-medium">Active</span>
                        </label>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="label" for="au-delay">
                                    @if ($row['trigger'] === 'win_back') Check frequency (hours) @else Wait after trigger (hours) @endif
                                </label>
                                <input id="au-delay" type="number" wire:model="delayHours" class="input" min="1" max="336">
                                @error('delayHours') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                            </div>
                            @if ($row['trigger'] === 'win_back')
                                <div>
                                    <label class="label" for="au-lapsed">Consider lapsed after (days)</label>
                                    <input id="au-lapsed" type="number" wire:model="lapsedDays" class="input" min="14" max="365">
                                    @error('lapsedDays') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                                </div>
                            @endif
                        </div>

                        <div>
                            <label class="label" for="au-subject">Subject</label>
                            <input id="au-subject" wire:model="subject" class="input">
                            @error('subject') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="label" for="au-body">Body</label>
                            <textarea id="au-body" wire:model="body" rows="6" class="input font-mono text-xs"></textarea>
                            <p class="mt-1 text-xs text-muted">Placeholders: <span class="font-mono">@verbatim{{name}} {{store}} {{shop_url}}@endverbatim</span></p>
                            @error('body') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                        </div>

                        <div class="flex gap-2">
                            <button type="submit" class="btn-accent btn-sm">Save</button>
                            <button type="button" wire:click="$set('editing', null)" class="btn-ghost btn-sm">Cancel</button>
                        </div>
                    </form>
                @endif
            </div>
        @endforeach
    </div>
</div>
