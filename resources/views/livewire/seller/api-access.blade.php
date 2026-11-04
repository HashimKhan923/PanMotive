<div class="max-w-3xl">
    <h1 class="text-2xl font-bold">API &amp; webhooks</h1>
    <p class="mt-1 text-sm text-muted">Connect your own inventory or order system. Base URL <code class="rounded bg-surface-2 px-1">{{ $baseUrl }}</code>.</p>

    {{-- Tokens --}}
    <section class="card mt-6 p-6" data-reveal>
        <h2 class="font-bold">Access tokens</h2>

        @if ($freshToken)
            <div class="mt-3 rounded-xl border border-accent/40 bg-accent/5 p-3">
                <p class="text-xs font-semibold text-accent">Copy this now — it won't be shown again</p>
                <div class="mt-1 flex items-center gap-2" x-data="{ c: false }">
                    <code class="flex-1 overflow-x-auto rounded bg-surface-2 px-2 py-1 text-xs">{{ $freshToken }}</code>
                    <button @click="navigator.clipboard.writeText('{{ $freshToken }}'); c = true; setTimeout(() => c = false, 1500)" class="btn-outline btn-sm" x-text="c ? 'Copied' : 'Copy'"></button>
                </div>
            </div>
        @endif

        <form wire:submit="createToken" class="mt-4 flex flex-wrap items-end gap-3">
            <div class="min-w-[180px] flex-1">
                <label class="label" for="tk-name">Name</label>
                <input id="tk-name" wire:model="tokenName" class="input h-10 text-sm" placeholder="Warehouse sync">
                @error('tokenName') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
            </div>
            <div>
                <span class="label">Scopes</span>
                <div class="flex flex-wrap gap-2 text-xs">
                    @foreach ($abilities as $a)
                        <label class="inline-flex items-center gap-1"><input type="checkbox" value="{{ $a }}" wire:model="tokenAbilities" class="rounded border-line text-accent focus:ring-accent"> {{ $a }}</label>
                    @endforeach
                </div>
            </div>
            <button class="btn-accent btn-sm">Create token</button>
        </form>

        <table class="mt-4 w-full text-sm">
            <tbody class="divide-y divide-line">
                @forelse ($tokens as $t)
                    <tr class="{{ $t->revoked_at ? 'opacity-50' : '' }}">
                        <td class="py-2">
                            <p class="font-medium">{{ $t->name }}</p>
                            <p class="text-xs text-muted">{{ $t->preview }}… · {{ implode(', ', $t->abilities) }}</p>
                        </td>
                        <td class="py-2 text-xs text-muted">{{ $t->last_used_at ? 'used '.$t->last_used_at->diffForHumans() : 'never used' }}</td>
                        <td class="py-2 text-right">
                            @if ($t->revoked_at)
                                <span class="text-xs text-muted">revoked</span>
                            @else
                                <button wire:click="revokeToken({{ $t->id }})" wire:confirm="Revoke this token?" class="text-xs text-muted hover:text-rose-500">Revoke</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td class="py-4 text-sm text-muted">No tokens yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    {{-- Webhooks --}}
    <section class="card mt-6 p-6" data-reveal>
        <h2 class="font-bold">Webhook endpoints</h2>
        <p class="mt-1 text-xs text-muted">We POST signed JSON to https URLs. Check <code>X-Panmotive-Signature</code> = HMAC-SHA256 of the raw body using the endpoint's secret, and reply 2xx within 8 seconds. Failed deliveries retry 4 times.</p>

        <form wire:submit="addEndpoint" class="mt-4 flex flex-wrap items-end gap-3">
            <div class="min-w-[220px] flex-1">
                <label class="label" for="wh-url">Endpoint URL</label>
                <input id="wh-url" wire:model="hookUrl" class="input h-10 text-sm" placeholder="https://my-erp.example.com/panmotive">
                @error('hookUrl') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
            </div>
            <div>
                <span class="label">Events</span>
                <div class="flex flex-wrap gap-2 text-xs">
                    @foreach ($events as $e)
                        <label class="inline-flex items-center gap-1"><input type="checkbox" value="{{ $e }}" wire:model="hookEvents" class="rounded border-line text-accent focus:ring-accent"> {{ $e }}</label>
                    @endforeach
                </div>
            </div>
            <button class="btn-accent btn-sm">Add</button>
        </form>
        @error('hookEvents') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror

        <div class="mt-4 space-y-2">
            @forelse ($endpoints as $ep)
                <div class="rounded-xl border border-line p-3 {{ $ep->is_active ? '' : 'opacity-60' }}">
                    <div class="flex items-center justify-between gap-3">
                        <code class="truncate text-xs">{{ $ep->url }}</code>
                        <div class="flex shrink-0 items-center gap-3 text-xs">
                            <span class="text-muted">{{ $ep->last_status ?? 'never fired' }}</span>
                            <button wire:click="sendTest({{ $ep->id }})" wire:loading.attr="disabled" wire:target="sendTest({{ $ep->id }})" class="font-medium text-accent">Send test</button>
                            <button wire:click="toggleEndpoint({{ $ep->id }})" class="font-medium text-accent">{{ $ep->is_active ? 'Pause' : 'Resume' }}</button>
                            <button wire:click="deleteEndpoint({{ $ep->id }})" wire:confirm="Delete this endpoint?" class="text-muted hover:text-rose-500" aria-label="Delete endpoint"><x-ui.icon name="trash" class="w-4 h-4" /></button>
                        </div>
                    </div>
                    <p class="mt-1 text-xs text-muted">{{ implode(', ', $ep->events) }}</p>
                    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs" x-data="{ copied: false }">
                        <span class="text-muted">Signing secret</span>
                        <code class="rounded bg-surface-2 px-1.5 py-0.5">{{ $revealedId === $ep->id ? $ep->secret : 'whsec_••••••••••••' }}</code>
                        <button wire:click="reveal({{ $ep->id }})" class="font-medium text-accent">{{ $revealedId === $ep->id ? 'Hide' : 'Reveal' }}</button>
                        @if ($revealedId === $ep->id)
                            <button type="button" @click="navigator.clipboard.writeText(@js($ep->secret)); copied = true; setTimeout(() => copied = false, 1500)" class="font-medium text-accent" x-text="copied ? 'Copied!' : 'Copy'"></button>
                        @endif
                        <button wire:click="rotateSecret({{ $ep->id }})" wire:confirm="Create a new secret? The old one stops working right away." class="text-muted hover:text-fg">Rotate</button>
                    </div>
                    @if ($ep->deliveries->isNotEmpty())
                        <details class="mt-2 text-xs">
                            <summary class="cursor-pointer text-muted">Recent deliveries</summary>
                            <ul class="mt-1 divide-y divide-line">
                                @foreach ($ep->deliveries as $d)
                                    <li class="flex justify-between gap-2 py-1">
                                        <span><span class="{{ $d->success ? 'text-emerald-600' : 'text-rose-500' }}">{{ $d->success ? '✓' : '✗' }} {{ $d->status }}</span> · {{ $d->event }}{{ $d->error ? ' · '.Str::limit($d->error, 60) : '' }}</span>
                                        <span class="shrink-0 text-muted">{{ $d->created_at->diffForHumans() }}{{ $d->duration_ms !== null ? ' · '.$d->duration_ms.'ms' : '' }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                </div>
            @empty
                <p class="text-sm text-muted">No endpoints yet.</p>
            @endforelse
        </div>
    </section>
</div>
