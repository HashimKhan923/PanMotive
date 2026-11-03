<div class="max-w-5xl">
    <h1 class="text-2xl font-bold">Ad credit</h1>
    <p class="mt-1 text-sm text-muted">Your prepaid advertising wallet. Ad clicks and boosts are paid from this balance — you're never charged more than you've loaded.</p>

    @if (request('cancelled'))
        <div class="mt-4 rounded-xl border border-line bg-surface-2 px-4 py-3 text-sm text-muted">Payment cancelled — you weren't charged.</div>
    @endif

    <div class="mt-6 grid gap-3 sm:grid-cols-3">
        <div class="card p-5">
            <p class="text-xs uppercase tracking-wide text-muted">Current balance</p>
            <p class="mt-1 text-3xl font-bold {{ $balance < 5 ? 'text-rose-600' : '' }}">${{ number_format($balance, 2) }}</p>
            @if ($daysLeft !== null)
                <p class="text-xs text-muted">≈ {{ $daysLeft }} {{ Str::plural('day', $daysLeft) }} left at ${{ number_format($dailyBurn, 2) }}/day</p>
            @elseif ($balance < 5)
                <p class="text-xs text-rose-600">Too low to run ads — top up below.</p>
            @endif
        </div>
        <div class="card p-5">
            <p class="text-xs uppercase tracking-wide text-muted">Spent last 30 days</p>
            <p class="mt-1 text-3xl font-bold">${{ number_format($totals['spent_30'], 2) }}</p>
            <p class="text-xs text-muted">clicks + boosts</p>
        </div>
        <div class="card p-5">
            <p class="text-xs uppercase tracking-wide text-muted">Bought all-time</p>
            <p class="mt-1 text-3xl font-bold">${{ number_format($totals['purchased'], 2) }}</p>
            <p class="text-xs text-muted">by card</p>
        </div>
    </div>

    <section class="card mt-6 p-6">
        <h2 class="font-bold">Add funds</h2>

        @if (! $paymentsReady)
            <div class="mt-3 rounded-xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm text-amber-700 dark:text-amber-400">
                Card payments aren't switched on for this marketplace yet (Stripe isn't configured), so ad credit can't be bought right now.
            </div>
        @endif

        <div class="mt-4 flex flex-wrap gap-2">
            @foreach ($packages as $p)
                <button type="button" wire:click="pick({{ $p }})"
                        class="rounded-xl border-2 px-5 py-3 text-lg font-bold transition {{ (float) $amount === (float) $p ? 'border-accent bg-accent/10 text-accent' : 'border-line hover:border-accent/50' }}">
                    ${{ $p }}
                </button>
            @endforeach
        </div>

        <div class="mt-4 flex flex-wrap items-end gap-3">
            <div>
                <label class="label" for="ac-amount">Or enter an amount ($)</label>
                <input id="ac-amount" type="number" step="1" min="{{ \App\Services\Seller\AdCreditPurchaseService::MIN }}" max="{{ \App\Services\Seller\AdCreditPurchaseService::MAX }}"
                       wire:model.live.debounce.300ms="amount" class="input h-11 w-40">
            </div>
            <button wire:click="buy" wire:loading.attr="disabled" wire:target="buy" @disabled(! $paymentsReady) class="btn-accent h-11">
                <span wire:loading.remove wire:target="buy">Pay ${{ number_format((float) $amount, 2) }} by card</span>
                <span wire:loading wire:target="buy">Opening secure checkout…</span>
            </button>
        </div>
        @error('amount') <p class="mt-2 text-sm text-rose-500">{{ $message }}</p> @enderror

        <p class="mt-3 flex items-center gap-1.5 text-xs text-muted">
            <x-ui.icon name="shield" class="w-3.5 h-3.5" />
            You'll pay on Stripe's secure page. Credit appears here as soon as the payment clears. Ad credit is non-refundable and doesn't expire.
        </p>

        @if ($canTestCredit)
            <div class="mt-4 border-t border-dashed border-line pt-4">
                <button wire:click="grantTestCredit" class="btn-outline btn-sm">+ Add $25 test credit</button>
                <span class="ml-2 text-xs text-muted">Development only — hidden on the live site.</span>
            </div>
        @endif
    </section>

    <section class="mt-6">
        <h2 class="font-bold">Activity</h2>
        <div class="mt-3 overflow-x-auto rounded-xl border border-line">
            <table class="w-full min-w-[560px] text-sm">
                <thead class="bg-surface-2 text-left text-xs uppercase text-muted">
                    <tr><th class="px-4 py-3">Date</th><th class="px-4 py-3">Description</th><th class="px-4 py-3 text-right">Amount</th><th class="px-4 py-3 text-right">Balance</th></tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($transactions as $t)
                        <tr class="bg-surface" wire:key="at-{{ $t->id }}">
                            <td class="px-4 py-3 text-muted">{{ $t->created_at->format('M j, Y g:i A') }}</td>
                            <td class="px-4 py-3">{{ $t->description }}</td>
                            <td class="px-4 py-3 text-right font-semibold {{ $t->amount < 0 ? '' : 'text-emerald-600' }}">{{ $t->amount < 0 ? '−' : '+' }}${{ number_format(abs((float) $t->amount), 2) }}</td>
                            <td class="px-4 py-3 text-right text-muted">${{ number_format((float) $t->balance_after, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-12 text-center text-sm text-muted">No ad credit activity yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $transactions->links() }}</div>
    </section>
</div>
