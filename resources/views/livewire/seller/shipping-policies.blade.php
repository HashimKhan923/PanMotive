<div class="max-w-3xl">
    <h1 class="text-2xl font-bold">Shipping policies</h1>
    <p class="mt-1 text-sm text-muted">Reusable handling times, rates and shipping restrictions. Assign a policy to a listing on its edit page; unassigned listings use your default.</p>

    {{-- list --}}
    <div class="mt-6 space-y-3">
        @forelse ($policies as $p)
            <div class="card p-5" wire:key="sp-{{ $p->id }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-semibold">
                            {{ $p->name }}
                            @if ($p->is_default)<span class="pill ml-1 !bg-surface-2 !text-fg">Default</span>@endif
                        </p>
                        <p class="mt-1 text-xs text-muted">
                            Ships in {{ $p->handling_days }} {{ Str::plural('business day', $p->handling_days) }} ·
                            <span class="{{ $p->method() === 'free' ? 'font-medium text-emerald-600' : '' }}">{{ $p->rateLabel() }}</span>
                            @if ($p->method() !== 'free' && $p->free_over !== null) · free over ${{ number_format($p->free_over, 2) }} @endif
                            · {{ $p->products_count }} {{ Str::plural('listing', $p->products_count) }}{{ $p->is_default ? ' + all unassigned' : '' }}
                        </p>
                        @if ($p->region_surcharges)
                            <p class="mt-1 text-xs text-muted">Surcharges:
                                @foreach ($p->region_surcharges as $st => $amt) {{ $st }} +${{ number_format($amt, 2) }}@if (!$loop->last), @endif @endforeach
                            </p>
                        @endif
                        @if ($p->excluded_states)
                            <p class="mt-1 text-xs text-rose-600">Doesn't ship to: {{ implode(', ', $p->excluded_states) }}</p>
                        @endif
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @unless ($p->is_default)
                            <button wire:click="makeDefault({{ $p->id }})" class="btn-ghost btn-sm">Make default</button>
                        @endunless
                        <button wire:click="edit({{ $p->id }})" class="btn-outline btn-sm">Edit</button>
                        <button wire:click="delete({{ $p->id }})" wire:confirm="Delete &quot;{{ $p->name }}&quot;? {{ $p->products_count }} {{ Str::plural('listing', $p->products_count) }} will switch to your default policy." class="btn-ghost btn-sm text-rose-500">Delete</button>
                    </div>
                </div>
            </div>
        @empty
            <div class="card grid place-items-center p-10 text-center text-sm text-muted">No policies yet — create one below. Until then, shipping is calculated by weight.</div>
        @endforelse
    </div>

    {{-- form --}}
    <form wire:submit="save" class="card mt-6 space-y-5 p-6">
        <h2 class="font-bold">{{ $editingId ? 'Edit policy' : 'New policy' }}</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="label" for="sp-name">Name</label>
                <input id="sp-name" wire:model="name" class="input" placeholder="e.g. Standard, Oversized, Free 3-day">
                @error('name') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="sp-handling">Handling time (business days)</label>
                <input id="sp-handling" type="number" wire:model="handlingDays" class="input" min="0" max="30">
                <p class="mt-1 text-xs text-muted">Shown to buyers as "ships within N days". 0 = same day.</p>
                @error('handlingDays') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
            </div>
        </div>

        <div>
            <span class="label">How buyers pay for shipping</span>
            <div class="mt-1 grid gap-2 sm:grid-cols-3">
                @foreach (['free' => ['Free shipping', 'You cover it — buyers love it'], 'flat' => ['Flat rate', 'One price per order'], 'weight' => ['By weight', 'Uses each listing\'s weight']] as $key => [$title, $hint])
                    <label class="cursor-pointer rounded-xl border-2 p-3 text-sm transition {{ $method === $key ? 'border-accent bg-accent/5' : 'border-line hover:border-accent/50' }}">
                        <input type="radio" wire:model.live="method" value="{{ $key }}" class="sr-only">
                        <span class="block font-semibold">{{ $title }}</span>
                        <span class="text-xs text-muted">{{ $hint }}</span>
                    </label>
                @endforeach
            </div>
        </div>

        @if ($method === 'flat')
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="sp-flat">Rate for the first item ($)</label>
                    <input id="sp-flat" type="number" step="0.01" wire:model="flatRate" class="input" placeholder="8.99">
                    @error('flatRate') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="sp-add">Each additional item ($) <span class="text-muted">— optional</span></label>
                    <input id="sp-add" type="number" step="0.01" wire:model="additionalRate" class="input" placeholder="0.00">
                    @error('additionalRate') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
            </div>
        @endif

        @if ($method !== 'free')
            <div class="sm:w-1/2">
                <label class="label" for="sp-free">Free shipping over ($) <span class="text-muted">— optional</span></label>
                <input id="sp-free" type="number" step="0.01" wire:model="freeOver" class="input" placeholder="No threshold">
                @error('freeOver') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
            </div>

            <div>
                <div class="flex items-center justify-between">
                    <span class="label mb-0">Regional surcharges</span>
                    <button type="button" wire:click="addSurcharge" class="btn-ghost btn-sm">+ Add</button>
                </div>
                @foreach ($surcharges as $i => $s)
                    <div class="mt-2 flex items-center gap-2" wire:key="sur-{{ $i }}">
                        <input wire:model="surcharges.{{ $i }}.state" maxlength="2" class="input h-9 w-20 uppercase" placeholder="AK" aria-label="State code">
                        <span class="text-sm text-muted">+ $</span>
                        <input type="number" step="0.01" wire:model="surcharges.{{ $i }}.amount" class="input h-9 w-28" aria-label="Surcharge amount">
                        <button type="button" wire:click="removeSurcharge({{ $i }})" class="btn-ghost btn-sm text-rose-500">Remove</button>
                    </div>
                    @error("surcharges.$i.state") <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                @endforeach
            </div>
        @endif

        <div>
            <span class="label">Don't ship to</span>
            <p class="text-xs text-muted">Buyers with an address in these states can't check out with listings on this policy — e.g. California for CARB-restricted exhaust and emissions parts.</p>
            <div class="mt-2 flex flex-wrap gap-2">
                @foreach ($presets as $label => $codes)
                    <button type="button" wire:click="addPreset('{{ $label }}')" class="rounded-full border border-dashed border-line px-3 py-1 text-xs hover:border-accent hover:text-accent">+ {{ $label }}</button>
                @endforeach
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                @foreach ($excludedStates as $code)
                    <span class="inline-flex items-center gap-1 rounded-full bg-rose-500/10 px-2.5 py-1 text-xs font-semibold text-rose-600" wire:key="ex-{{ $code }}">
                        {{ $code }}
                        <button type="button" wire:click="removeExcluded('{{ $code }}')" aria-label="Remove {{ $code }}"><x-ui.icon name="x" class="w-4 h-4" /></button>
                    </span>
                @endforeach
                <input wire:model="stateToAdd" wire:keydown.enter.prevent="addExcluded" maxlength="2" class="input h-8 w-20 uppercase text-sm" placeholder="TX" aria-label="Add a state">
                <button type="button" wire:click="addExcluded" class="btn-ghost btn-sm">Add</button>
            </div>
            @error('stateToAdd') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
        </div>

        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" wire:model="isDefault" class="rounded border-line text-accent focus:ring-accent">
            <span>Use as my default policy</span>
        </label>

        <div class="flex gap-2">
            <button type="submit" class="btn-accent btn-sm">Save policy</button>
            @if ($editingId)<button type="button" wire:click="newPolicy" class="btn-ghost btn-sm">Cancel</button>@endif
        </div>
    </form>
</div>
