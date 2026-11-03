<div class="max-w-4xl">
    <h1 class="text-2xl font-bold">Team &amp; permissions</h1>
    <p class="mt-1 text-sm text-muted">Invite staff to help run the store. Each person signs in with their own account; you control what they can do.</p>

    {{-- invite --}}
    <form wire:submit="invite" class="card mt-6 flex flex-wrap items-end gap-3 p-5">
        <div class="min-w-[220px] flex-1">
            <label class="label" for="tm-email">Invite by email</label>
            <input id="tm-email" wire:model="inviteEmail" type="email" class="input" placeholder="colleague@example.com">
            @error('inviteEmail') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="label" for="tm-role">Role</label>
            <select id="tm-role" wire:model="inviteRole" class="input w-auto">
                <option value="manager">Manager — everything except team</option>
                <option value="staff">Staff — orders, listings, fulfilment</option>
                <option value="viewer">Viewer — read only</option>
            </select>
        </div>
        <button class="btn-accent">Send invite</button>
    </form>

    {{-- members --}}
    <div class="mt-6 space-y-3">
        @forelse ($members as $m)
            <div class="card p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-semibold">{{ $m->name ?: $m->email }}</p>
                        <p class="text-xs text-muted">
                            {{ $m->email }} · <span class="capitalize">{{ $m->role }}</span> ·
                            <span class="capitalize">{{ $m->status }}</span>
                            @if ($m->last_active_at) · active {{ $m->last_active_at->diffForHumans() }} @endif
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($m->status === 'invited')
                            <button wire:click="resend({{ $m->id }})" class="btn-outline btn-sm">Resend</button>
                            <button wire:click="setStatus({{ $m->id }}, 'revoked')" class="btn-ghost btn-sm text-rose-500">Withdraw</button>
                        @elseif ($m->status === 'active')
                            <button wire:click="startEdit({{ $m->id }})" class="btn-outline btn-sm">Permissions</button>
                            <button wire:click="setStatus({{ $m->id }}, 'suspended')" class="btn-ghost btn-sm">Suspend</button>
                            <button wire:click="setStatus({{ $m->id }}, 'revoked')" class="btn-ghost btn-sm text-rose-500">Remove</button>
                        @elseif ($m->status === 'suspended')
                            <button wire:click="setStatus({{ $m->id }}, 'active')" class="btn-accent btn-sm">Reactivate</button>
                            <button wire:click="setStatus({{ $m->id }}, 'revoked')" class="btn-ghost btn-sm text-rose-500">Remove</button>
                        @endif
                    </div>
                </div>

                @if ($editingId === $m->id)
                    <div class="mt-4 border-t border-line pt-4">
                        <div class="flex flex-wrap gap-2">
                            @foreach (['manager', 'staff', 'viewer'] as $preset)
                                <button type="button" wire:click="usePreset('{{ $preset }}')"
                                    class="rounded-full border px-3 py-1 text-xs font-medium {{ $editRole === $preset ? 'border-accent bg-accent/10 text-accent' : 'border-line text-muted' }}">
                                    {{ ucfirst($preset) }}
                                </button>
                            @endforeach
                        </div>
                        <div class="mt-4 grid gap-2 sm:grid-cols-2">
                            @foreach ($abilityCatalog as $key => $label)
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" wire:model="editAbilities" value="{{ $key }}" class="rounded border-line text-accent focus:ring-accent">
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                        @error('editAbilities') <p class="mt-2 text-xs text-rose-500">{{ $message }}</p> @enderror
                        <div class="mt-4 flex gap-2">
                            <button wire:click="saveEdit" class="btn-accent btn-sm">Save permissions</button>
                            <button wire:click="$set('editingId', null)" class="btn-ghost btn-sm">Cancel</button>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <div class="card grid place-items-center p-12 text-center">
                <x-ui.icon name="user" class="w-9 h-9 text-muted" />
                <p class="mt-2 font-semibold">No team members yet</p>
                <p class="mt-1 text-sm text-muted">Invite someone above to share the workload.</p>
            </div>
        @endforelse
    </div>

    {{-- activity --}}
    @if ($activity->isNotEmpty())
        <div class="card mt-6 p-5">
            <h2 class="font-bold">Recent team activity</h2>
            <ul class="mt-3 divide-y divide-line text-sm">
                @foreach ($activity as $a)
                    <li class="flex items-center justify-between py-2">
                        <span><span class="font-medium">{{ $a->actor_name ?? 'Someone' }}</span> {{ $a->action }}</span>
                        <span class="text-xs text-muted">{{ $a->created_at->diffForHumans() }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
