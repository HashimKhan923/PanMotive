<div class="max-w-4xl">
    <h1 class="text-2xl font-bold">Account health</h1>
    <p class="mt-1 text-sm text-muted">Policy standing, violations and buyer claims. Keeping this green protects your search placement and payouts.</p>

    {{-- standing --}}
    @php
        $badge = [
            'good' => ['Good standing', '!bg-emerald-500/15 !text-emerald-600'],
            'needs_attention' => ['Needs attention', '!bg-amber-500/15 !text-amber-600'],
            'at_risk' => ['At risk', '!bg-rose-500/15 !text-rose-600'],
        ][$standing['level']];
    @endphp
    <div class="card mt-6 p-6">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <span class="pill {{ $badge[1] }}">{{ $badge[0] }}</span>
                <p class="mt-2 text-3xl font-bold">{{ $standing['score'] }}<span class="text-base font-medium text-muted">/100</span></p>
            </div>
            <div class="grid grid-cols-3 gap-6 text-sm">
                <div><p class="text-muted">Active violations</p><p class="text-lg font-bold">{{ $standing['active'] }}</p></div>
                <div><p class="text-muted">Severe</p><p class="text-lg font-bold {{ $standing['severe'] ? 'text-rose-500' : '' }}">{{ $standing['severe'] }}</p></div>
                <div><p class="text-muted">Claim rate</p><p class="text-lg font-bold">{{ $standing['claim_rate'] }}%</p></div>
            </div>
        </div>
        @if ($standing['at_risk'])
            <p class="mt-4 rounded-lg bg-rose-500/10 px-4 py-2 text-sm text-rose-600">Your account is at risk of suspension. Resolve open claims and appeal any violations you believe are mistaken.</p>
        @endif
    </div>

    {{-- claims --}}
    <div class="card mt-6 p-6">
        <h2 class="font-bold">Buyer claims</h2>
        <div class="mt-4 space-y-3">
            @forelse ($claims as $c)
                <div class="rounded-xl border border-line p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="font-semibold">{{ $c->claim_number }} · {{ \App\Models\BuyerClaim::REASONS[$c->reason] ?? $c->reason }}</p>
                            <p class="text-xs text-muted">
                                Order {{ $c->orderItem?->order?->order_number }} · {{ $c->buyer?->name }} ·
                                ${{ number_format($c->amount, 2) }} ·
                                <span class="capitalize">{{ str_replace('_', ' ', $c->status) }}</span>
                                @if ($c->isOverdue()) · <span class="text-rose-500">response overdue</span>
                                @elseif ($c->status === 'open' && $c->respond_by) · respond by {{ $c->respond_by->format('M j, g:i a') }} @endif
                            </p>
                            <p class="mt-2 text-sm">{{ $c->detail }}</p>
                            @if ($c->seller_response)
                                <p class="mt-2 rounded bg-surface-2 px-3 py-2 text-sm"><span class="font-medium">Your response:</span> {{ $c->seller_response }}</p>
                            @endif
                        </div>
                        <div class="flex shrink-0 flex-col items-end gap-1.5">
                            @if ($c->status === 'open')
                                <button wire:click="startResponse({{ $c->id }})" class="btn-accent btn-sm">Respond</button>
                            @endif
                            @if (in_array($c->status, ['open', 'seller_responded'], true))
                                <button wire:click="refundClaim({{ $c->id }})"
                                        wire:confirm="Refund the buyer in full for this item and close the claim? It won't count against your account."
                                        class="btn-ghost btn-sm">Refund &amp; close</button>
                            @endif
                            @if ($c->resolvedBySeller())
                                <span class="text-xs text-emerald-600">Resolved by you — not counted</span>
                            @endif
                        </div>
                    </div>
                    @if ($claimId === $c->id)
                        <form wire:submit="submitResponse" class="mt-3 border-t border-line pt-3">
                            <textarea wire:model="claimResponse" rows="3" class="input" placeholder="Explain what happened and how you'll resolve it (refund, replacement, tracking proof…)."></textarea>
                            @error('claimResponse') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                            <div class="mt-2 flex gap-2">
                                <button class="btn-accent btn-sm">Send response</button>
                                <button type="button" wire:click="$set('claimId', null)" class="btn-ghost btn-sm">Cancel</button>
                            </div>
                        </form>
                    @endif
                </div>
            @empty
                <p class="text-sm text-muted">No claims. Nicely done.</p>
            @endforelse
        </div>
    </div>

    {{-- violations --}}
    <div class="card mt-6 p-6">
        <h2 class="font-bold">Policy violations</h2>
        <div class="mt-4 space-y-3">
            @forelse ($violations as $v)
                <div class="rounded-xl border border-line p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="font-semibold">
                                {{ $v->title }}
                                <span class="pill ml-1 capitalize {{ ['notice' => '!bg-surface-2 !text-muted', 'warning' => '!bg-amber-500/15 !text-amber-600', 'severe' => '!bg-rose-500/15 !text-rose-600'][$v->severity] }}">{{ $v->severity }}</span>
                                <span class="pill ml-1 capitalize">{{ $v->status }}</span>
                            </p>
                            @if ($v->description)<p class="mt-1 text-sm text-muted">{{ $v->description }}</p>@endif
                            <p class="mt-1 text-xs text-muted">
                                Issued {{ $v->created_at->format('M j, Y') }}
                                @if ($v->expires_at) · auto-lifts {{ $v->expires_at->format('M j, Y') }} @endif
                            </p>
                            @if ($v->appeal_text)
                                <p class="mt-2 rounded bg-surface-2 px-3 py-2 text-sm"><span class="font-medium">Your appeal:</span> {{ $v->appeal_text }}</p>
                            @endif
                        </div>
                        @if ($v->status === 'active')
                            <button wire:click="startAppeal({{ $v->id }})" class="btn-outline btn-sm">Appeal</button>
                        @endif
                    </div>
                    @if ($appealId === $v->id)
                        <form wire:submit="submitAppeal" class="mt-3 border-t border-line pt-3">
                            <textarea wire:model="appealText" rows="3" class="input" placeholder="Explain why this violation is mistaken, with evidence (order numbers, tracking, photos you can provide)."></textarea>
                            @error('appealText') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                            <div class="mt-2 flex gap-2">
                                <button class="btn-accent btn-sm">Submit appeal</button>
                                <button type="button" wire:click="$set('appealId', null)" class="btn-ghost btn-sm">Cancel</button>
                            </div>
                        </form>
                    @endif
                </div>
            @empty
                <p class="text-sm text-muted">No violations on record.</p>
            @endforelse
        </div>
    </div>
</div>
