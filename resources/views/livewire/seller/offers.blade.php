<div class="max-w-4xl">
    <h1 class="text-2xl font-bold">Offers</h1>
    <p class="mt-1 text-sm text-muted">{{ $openCount }} awaiting your response. Accepting mints a single-use code for that buyer, valid 48 hours.</p>

    <div class="mt-6 flex gap-1 border-b border-line text-sm">
        @foreach (['open' => 'Open', 'accepted' => 'Accepted', 'closed' => 'Closed'] as $key => $label)
            <button wire:click="$set('filter', '{{ $key }}')"
                    class="-mb-px border-b-2 px-3 py-2 font-medium transition {{ $filter === $key ? 'border-accent text-accent' : 'border-transparent text-muted hover:text-fg' }}">{{ $label }}</button>
        @endforeach
    </div>

    <div class="mt-4 space-y-3">
        @forelse ($offers as $offer)
            @php($list = $offer->product->effectivePrice())
            <div class="card p-5" wire:key="offer-{{ $offer->id }}" data-reveal>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <a href="{{ route('products.show', $offer->product->slug) }}" target="_blank" class="font-semibold hover:text-accent">{{ $offer->product->title }}</a>
                        <p class="text-sm text-muted">{{ $offer->buyer->name }} · qty {{ $offer->quantity }}</p>
                        @if ($offer->message)<p class="mt-1 text-sm">“{{ $offer->message }}”</p>@endif
                    </div>
                    <div class="text-right">
                        @php($offered = (float) $offer->offer_price)
                        @php($pctOff = $list > 0 ? round((1 - $offered / $list) * 100) : 0)
                        <p class="text-lg font-bold text-accent">${{ number_format($offered, 2) }}<span class="text-xs font-normal text-muted">/ea</span></p>
                        <p class="text-xs text-muted"><span class="line-through">${{ number_format($list, 2) }}</span> · {{ $pctOff }}% off</p>
                        @if ($offer->product->cost_price)
                            @php($profit = ($offered - (float) $offer->product->cost_price - $offered * (float) (auth()->user()->sellerProfile?->commissionRate() ?? config('marketplace.default_commission_rate')) / 100) * $offer->quantity)
                            <p class="text-xs {{ $profit < 0 ? 'font-semibold text-rose-600' : 'text-emerald-600' }}">
                                {{ $profit < 0 ? 'Loss' : 'Profit' }} ${{ number_format(abs($profit), 2) }} after cost &amp; fees
                            </p>
                        @endif
                        <span class="pill mt-1 capitalize">{{ $offer->status }}</span>
                    </div>
                </div>

                @if ($offer->status === 'accepted')
                    <p class="mt-3 rounded-lg bg-emerald-500/10 px-3 py-2 text-xs text-emerald-600">Deal at ${{ number_format($offer->listPrice(), 2) }}/ea — the buyer's price is held until {{ $offer->expires_at?->format('M j, g:i A') }}.</p>
                @elseif ($offer->status === 'countered')
                    <p class="mt-3 rounded-lg bg-accent/10 px-3 py-2 text-xs">You countered at <span class="font-semibold">${{ number_format($offer->counter_price, 2) }}/ea</span> — waiting on the buyer until {{ $offer->expires_at?->format('M j, g:i A') }}.</p>
                @elseif ($offer->status === 'pending')
                    <div class="mt-4 flex flex-wrap items-end gap-2 border-t border-line pt-4">
                        <button wire:click="accept({{ $offer->id }})" class="btn-accent btn-sm">Accept ${{ number_format($offer->listPrice(), 2) }}</button>
                        <div class="flex items-end gap-1">
                            <div>
                                <label class="label" for="ct-{{ $offer->id }}">Counter ($/ea)</label>
                                <input id="ct-{{ $offer->id }}" type="number" step="0.01" wire:model="counter.{{ $offer->id }}" class="input h-9 w-28 text-sm"
                                       placeholder="{{ number_format(round(((float) $offer->offer_price + $list) / 2, 2), 2) }}" title="Suggested: halfway between their offer and your price">
                            </div>
                            <button wire:click="sendCounter({{ $offer->id }})" class="btn-outline btn-sm">Send</button>
                        </div>
                        <button wire:click="decline({{ $offer->id }})" class="btn-ghost btn-sm text-muted hover:text-rose-500">Decline</button>
                        @error("counter.{$offer->id}") <p class="w-full text-xs text-rose-500">{{ $message }}</p> @enderror
                    </div>
                    <p class="mt-2 text-xs text-muted">Expires {{ $offer->expires_at?->diffForHumans() }}</p>
                @endif
            </div>
        @empty
            <div class="card grid place-items-center p-16 text-center">
                <x-ui.icon name="tag" class="w-10 h-10 text-muted" />
                <p class="mt-3 font-semibold">No offers here</p>
                <p class="mt-1 text-sm text-muted">Turn on "Accept offers" on a listing to let buyers negotiate.</p>
            </div>
        @endforelse
    </div>

    <div class="mt-4">{{ $offers->links() }}</div>
</div>
