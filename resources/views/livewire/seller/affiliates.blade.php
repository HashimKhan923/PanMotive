<div class="max-w-4xl">
    <h1 class="text-2xl font-bold">Affiliate program</h1>
    <p class="mt-1 text-sm text-muted">Let creators and customers earn commission for driving sales to your store. Commission is set aside from that order's payout, and becomes payable once your items are delivered and the {{ config('marketplace.marketing.affiliate_hold_days') }}-day return window passes — refunded orders earn nothing.</p>

    <form wire:submit="save" class="card mt-6 space-y-4 p-6" data-reveal>
        <label class="flex items-center gap-3">
            <input type="checkbox" wire:model="isActive" class="rounded border-line text-accent focus:ring-accent">
            <span class="font-bold">Program is live</span>
        </label>
        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label class="label" for="af-pct">Commission %</label>
                <input id="af-pct" type="number" step="0.5" wire:model="commissionPct" class="input">
                @error('commissionPct') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="af-cookie">Attribution window (days)</label>
                <input id="af-cookie" type="number" wire:model="cookieDays" class="input">
            </div>
            <label class="flex items-end gap-2 pb-2 text-sm">
                <input type="checkbox" wire:model="autoApprove" class="rounded border-line text-accent focus:ring-accent"> Auto-approve applicants
            </label>
        </div>
        <div>
            <label class="label" for="af-terms">Terms (optional)</label>
            <textarea id="af-terms" wire:model="terms" rows="3" class="input"></textarea>
        </div>
        @if ($program->seller->sellerProfile?->slug)
            <p class="text-xs text-muted">Join page: <code>{{ route('affiliate.join.form', $program->seller->sellerProfile) }}</code></p>
        @endif
        <button type="submit" class="btn-accent btn-sm">Save program</button>
    </form>

    <h2 class="mt-8 font-bold">Affiliates</h2>
    <div class="mt-3 overflow-x-auto rounded-xl border border-line">
        <table class="w-full min-w-[720px] text-sm">
            <thead class="bg-surface-2 text-left text-xs uppercase text-muted">
                <tr><th class="px-4 py-3">Affiliate</th><th class="px-4 py-3">Code</th><th class="px-4 py-3">Clicks</th><th class="px-4 py-3">Sales</th><th class="px-4 py-3">Earned</th><th class="px-4 py-3">Owed</th><th class="px-4 py-3">Status</th><th class="px-4 py-3"></th></tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($affiliates as $a)
                    <tr class="bg-surface" wire:key="aff-{{ $a->id }}">
                        <td class="px-4 py-3">
                            <p class="font-medium">{{ $a->name }}</p>
                            <p class="text-xs text-muted">{{ $a->email }}{{ $a->channel ? ' · '.$a->channel : '' }}</p>
                        </td>
                        <td class="px-4 py-3 font-mono">{{ $a->code }}</td>
                        <td class="px-4 py-3 text-muted">{{ number_format($a->clicks) }}</td>
                        <td class="px-4 py-3 text-muted">{{ $a->conversions }}</td>
                        <td class="px-4 py-3">${{ number_format($a->earnings, 2) }}</td>
                        <td class="px-4 py-3 font-semibold">${{ number_format($a->unpaidBalance(), 2) }}</td>
                        <td class="px-4 py-3"><span class="pill capitalize">{{ $a->status }}</span></td>
                        <td class="px-4 py-3 text-right">
                            @if ($a->status === 'pending')
                                <button wire:click="decide({{ $a->id }}, 'approved')" class="text-xs font-medium text-emerald-600 hover:underline">Approve</button>
                                <button wire:click="decide({{ $a->id }}, 'rejected')" class="ml-2 text-xs text-muted hover:text-rose-500">Reject</button>
                            @elseif ($a->status === 'approved')
                                @if (($payable = $a->payableBalance()) > 0)
                                    <button wire:click="pay({{ $a->id }})" class="btn-accent btn-sm">Pay ${{ number_format($payable, 2) }}</button>
                                @endif
                                @if (($pending = $a->pendingBalance()) > 0)
                                    <p class="mt-1 text-[11px] text-muted">${{ number_format($pending, 2) }} pending — payable after the {{ config('marketplace.marketing.affiliate_hold_days') }}-day return window</p>
                                @endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-12 text-center text-sm text-muted">No affiliates yet. Share your join link.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
