<div class="mt-3">
    @php($existing = $this->existingOffer)
    @if ($existing && $existing->status === 'accepted')
        <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3 text-sm">
            <p class="font-semibold text-emerald-600 dark:text-emerald-400">Offer accepted — ${{ number_format($existing->listPrice(), 2) }}/ea × {{ $existing->quantity }}</p>
            <p class="mt-1 text-xs text-muted">Price held for you until {{ $existing->expires_at?->format('M j, g:i A') }}.</p>
            <button wire:click="buyNow" class="btn-accent btn-sm mt-2 w-full">Buy now at your price</button>
        </div>
    @elseif ($existing && $existing->status === 'countered')
        <div class="rounded-xl border border-accent/30 bg-accent/10 p-3 text-sm">
            <p class="font-semibold text-accent">Seller countered at ${{ number_format($existing->counter_price, 2) }}/ea × {{ $existing->quantity }}</p>
            <p class="mt-1 text-xs text-muted">You offered ${{ number_format($existing->offer_price, 2) }} · respond by {{ $existing->expires_at?->format('M j, g:i A') }}</p>
            <div class="mt-2 flex gap-2">
                <button wire:click="acceptCounter" class="btn-accent btn-sm flex-1">Accept ${{ number_format($existing->counter_price, 2) }}</button>
                <button wire:click="declineCounter" wire:confirm="Decline the seller's counter-offer?" class="btn-ghost btn-sm">Decline</button>
            </div>
        </div>
    @elseif ($existing)
        <p class="text-xs text-muted">Offer of ${{ number_format($existing->offer_price, 2) }}/ea pending — the seller has until {{ $existing->expires_at?->format('M j, g A') }}.</p>
    @elseif ($open)
        <form wire:submit="submit" class="rounded-xl border border-line p-3">
            <div class="flex gap-2">
                <div class="flex-1">
                    <label class="label" for="of-price">Your price / each</label>
                    <input id="of-price" type="number" step="0.01" wire:model="price" class="input h-10 text-sm">
                    @error('price') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
                <div class="w-20">
                    <label class="label" for="of-qty">Qty</label>
                    <input id="of-qty" type="number" wire:model="quantity" class="input h-10 text-sm">
                </div>
            </div>
            <textarea wire:model="message" rows="2" class="input mt-2 text-sm" placeholder="Optional note to the seller"></textarea>
            <div class="mt-2 flex gap-2">
                <button class="btn-accent btn-sm">Send offer</button>
                <button type="button" wire:click="$set('open', false)" class="btn-ghost btn-sm">Cancel</button>
            </div>
        </form>
    @else
        <button wire:click="$set('open', true)" class="btn-outline btn-sm w-full">
            <x-ui.icon name="tag" class="w-4 h-4" /> Make an offer
        </button>
    @endif
</div>
