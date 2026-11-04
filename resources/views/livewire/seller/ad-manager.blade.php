<div class="max-w-4xl">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">Ad manager</h1>
            <p class="mt-1 text-sm text-muted">Targeted sponsored placements across search, category pages and the homepage. Pay per click from ad credit.</p>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('seller.ad-credit.index') }}" class="rounded-lg border px-3 py-2 text-sm transition hover:border-accent {{ $adCredit < 5 ? 'border-rose-500/50 text-rose-600' : 'border-line' }}">
                Ad credit <span class="font-bold">${{ number_format($adCredit, 2) }}</span> <span class="font-medium text-accent">+ Add</span>
            </a>
            <button wire:click="newCampaign" class="btn-accent btn-sm"><x-ui.icon name="plus" class="w-4 h-4" /> New campaign</button>
        </div>
    </div>

    @if ($showForm)
        <form wire:submit="save" class="card mt-6 space-y-5 p-6" data-reveal>
            <h2 class="font-bold">{{ $editingId ? 'Edit campaign' : 'New campaign' }}</h2>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <label class="label" for="am-name">Name</label>
                    <input id="am-name" wire:model="name" class="input">
                    @error('name') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="am-obj">Objective</label>
                    <select id="am-obj" wire:model="objective" class="input">
                        <option value="sales">Sales</option>
                        <option value="traffic">Traffic</option>
                        <option value="awareness">Awareness</option>
                    </select>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="am-budget">Daily budget ($)</label>
                    <input id="am-budget" type="number" step="0.5" wire:model="dailyBudget" class="input">
                    @error('dailyBudget') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="am-end">End date (optional)</label>
                    <input id="am-end" type="datetime-local" wire:model="endsAt" class="input">
                </div>
            </div>

            <div>
                <label class="label">Placements &amp; cost per click</label>
                <div class="flex flex-wrap gap-2 text-sm">
                    @foreach (['search' => 'Search results', 'category' => 'Category pages', 'home' => 'Homepage'] as $key => $label)
                        <label class="inline-flex items-center gap-2 rounded-lg border border-line px-3 py-1.5 has-[:checked]:border-accent has-[:checked]:bg-accent/5">
                            <input type="checkbox" value="{{ $key }}" wire:model="placements" class="rounded border-line text-accent focus:ring-accent">
                            {{ $label }} · ${{ number_format($cpc[$key], 2) }}/click
                        </label>
                    @endforeach
                </div>
                @error('placements') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="label">Target categories</label>
                    <select multiple wire:model="categories" class="input h-28 text-sm">
                        @foreach ($allCategories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="label">Target vehicle makes</label>
                    <select multiple wire:model="vehicleMakeIds" class="input h-28 text-sm">
                        @foreach ($makes as $m)<option value="{{ $m->id }}">{{ $m->name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="label" for="am-kw">Keywords (comma-separated)</label>
                    <textarea id="am-kw" wire:model="keywords" rows="4" class="input text-sm" placeholder="brake pads, rotors, ceramic"></textarea>
                </div>
            </div>
            <p class="text-xs text-muted">Leave all targeting empty for a broad campaign. Any matching dimension makes an ad eligible.</p>

            <div>
                <div class="flex items-center justify-between">
                    <span class="label mb-0">Creatives</span>
                    <button type="button" wire:click="addCreative" class="text-xs font-medium text-fg">+ Add creative</button>
                </div>
                <div class="mt-2 space-y-3">
                    @foreach ($creatives as $i => $cr)
                        <div wire:key="cr-{{ $i }}" class="rounded-xl border border-line p-3">
                            <div class="flex flex-wrap items-end gap-2">
                                <div class="min-w-[200px] flex-1">
                                    <label class="label" for="cr-p-{{ $i }}">Product</label>
                                    <select id="cr-p-{{ $i }}" wire:model="creatives.{{ $i }}.product_id" class="input h-9 text-sm">
                                        <option value="">Choose…</option>
                                        @foreach ($products as $p)<option value="{{ $p->id }}">{{ $p->title }}</option>@endforeach
                                    </select>
                                    @error("creatives.$i.product_id") <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                                </div>
                                <button type="button" wire:click="generateCreative({{ $i }})" class="btn-brand btn-sm"><x-ui.icon name="sparkles" class="w-4 h-4" /> AI copy</button>
                                <button type="button" wire:click="removeCreative({{ $i }})" class="btn-ghost !p-2 text-muted hover:text-rose-500"><x-ui.icon name="trash" class="w-4 h-4" /></button>
                            </div>
                            <input wire:model="creatives.{{ $i }}.headline" maxlength="40" class="input mt-2 h-9 text-sm" placeholder="Headline (≤40 chars)">
                            <input wire:model="creatives.{{ $i }}.primary_text" maxlength="300" class="input mt-2 h-9 text-sm" placeholder="Primary text">
                        </div>
                    @endforeach
                </div>
                @error('creatives') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
            </div>

            <div class="flex gap-2">
                <button type="submit" class="btn-accent btn-sm">Launch campaign</button>
                <button type="button" wire:click="$set('showForm', false)" class="btn-ghost btn-sm">Cancel</button>
            </div>
        </form>
    @endif

    <div class="mt-6 space-y-3" data-reveal-group>
        @forelse ($campaigns as $c)
            @php($m = $c->metrics())
            <div class="card p-5" wire:key="ac-{{ $c->id }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-semibold">{{ $c->name }}
                            @if ($c->paused_for_credit)
                                <a href="{{ route('seller.ad-credit.index') }}" class="pill ml-1 !bg-rose-500/10 !text-rose-600 hover:underline">Paused — out of credit · top up</a>
                            @else
                                <span class="pill ml-1 capitalize {{ $c->status === 'active' ? '!text-emerald-600' : '' }}">{{ $c->status }}</span>
                            @endif
                        </p>
                        <p class="text-xs text-muted">
                            {{ ucfirst($c->objective) }} · ${{ number_format($c->daily_budget, 2) }}/day · {{ implode(', ', $c->targets('placements')) }}
                            · {{ $c->creatives->count() }} {{ Str::plural('creative', $c->creatives->count()) }}
                        </p>
                    </div>
                    <div class="flex gap-2 text-sm">
                        <button wire:click="toggle({{ $c->id }})" class="font-medium text-accent hover:underline">{{ $c->status === 'active' ? 'Pause' : 'Resume' }}</button>
                        <button wire:click="edit({{ $c->id }})" class="font-medium text-accent hover:underline">Edit</button>
                        <button wire:click="delete({{ $c->id }})" wire:confirm="Delete campaign?" class="text-muted hover:text-rose-500">Delete</button>
                    </div>
                </div>
                <div class="mt-3 grid grid-cols-3 gap-2 border-t border-line pt-3 text-center text-sm sm:grid-cols-7">
                    <div><p class="text-xs text-muted">Impr.</p><p class="font-bold">{{ number_format($m['impressions']) }}</p></div>
                    <div><p class="text-xs text-muted">Clicks</p><p class="font-bold">{{ number_format($m['clicks']) }}</p></div>
                    <div><p class="text-xs text-muted">CTR</p><p class="font-bold">{{ $m['ctr'] }}%</p></div>
                    <div><p class="text-xs text-muted">CPC</p><p class="font-bold">${{ number_format($m['cpc'], 2) }}</p></div>
                    <div><p class="text-xs text-muted">Spend</p><p class="font-bold">${{ number_format($m['spend'], 2) }}</p></div>
                    <div><p class="text-xs text-muted">Orders</p><p class="font-bold">{{ $m['orders'] }}</p></div>
                    <div><p class="text-xs text-muted">ROAS</p><p class="font-bold {{ $m['roas'] >= 2 ? 'text-emerald-600' : '' }}">{{ $m['roas'] }}×</p></div>
                </div>
            </div>
        @empty
            <div class="card grid place-items-center p-16 text-center">
                <x-ui.icon name="chart" class="w-12 h-12 text-muted" />
                <p class="mt-3 font-semibold">No ad campaigns yet</p>
                <p class="mt-1 text-sm text-muted">Target buyers by category, vehicle or keyword and only pay for clicks.</p>
            </div>
        @endforelse
    </div>
</div>
