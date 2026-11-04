<div class="max-w-3xl">
    <h1 class="text-2xl font-bold">Store settings</h1>
    <p class="mt-1 text-sm text-muted">How your storefront presents to buyers and how you fulfil orders.</p>

    <form wire:submit="save" class="mt-6 space-y-6">
        <section class="card p-6">
            <h2 class="font-bold">Business</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="ss-name">Business name</label>
                    <input id="ss-name" wire:model="business_name" class="input">
                    @error('business_name') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="ss-type">Business type</label>
                    <select id="ss-type" wire:model="business_type" class="input">
                        <option value="sole_prop">Sole proprietor</option>
                        <option value="llc">LLC</option>
                        <option value="corp">Corporation</option>
                    </select>
                </div>
            </div>
            <div class="mt-4">
                <label class="label" for="ss-bio">Store bio</label>
                <textarea id="ss-bio" wire:model="bio" rows="3" class="input" placeholder="Tell buyers who you are and what you specialise in."></textarea>
                @error('bio') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
            </div>
        </section>

        <section class="card p-6">
            <h2 class="font-bold">Fulfilment</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="ss-carrier">Default carrier</label>
                    <select id="ss-carrier" wire:model="default_carrier" class="input">
                        <option value="usps">USPS</option>
                        <option value="ups">UPS</option>
                        <option value="fedex">FedEx</option>
                        <option value="dhl">DHL</option>
                    </select>
                </div>
                <div>
                    <label class="label" for="ss-proc">Processing time</label>
                    <select id="ss-proc" wire:model="processing_days" class="input">
                        <option value="1-2">1–2 business days</option>
                        <option value="3-5">3–5 business days</option>
                        <option value="5-7">5–7 business days</option>
                    </select>
                </div>
            </div>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="ss-handling">Handling fee ($)</label>
                    <input id="ss-handling" type="number" step="0.01" wire:model="handling_fee" class="input">
                    @error('handling_fee') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="ss-freeship">Free shipping over ($)</label>
                    <input id="ss-freeship" type="number" step="0.01" wire:model="free_shipping_threshold" class="input" placeholder="No threshold">
                    @error('free_shipping_threshold') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="mt-6 border-t border-line pt-4">
                <p class="text-sm font-semibold">Ship-from address</p>
                <p class="text-xs text-muted">Printed on your shipping labels as the return address. Never shown to buyers on your store page.</p>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <input wire:model="ship_from.name" class="input" placeholder="Contact name" aria-label="Contact name">
                    <input wire:model="ship_from.company" class="input" placeholder="Company (optional)" aria-label="Company">
                    <input wire:model="ship_from.line1" class="input sm:col-span-2" placeholder="Street address" aria-label="Street address">
                    <input wire:model="ship_from.line2" class="input sm:col-span-2" placeholder="Suite, unit (optional)" aria-label="Address line 2">
                    <input wire:model="ship_from.city" class="input" placeholder="City" aria-label="City">
                    <div class="grid grid-cols-2 gap-3">
                        <input wire:model="ship_from.state" class="input" placeholder="State" maxlength="40" aria-label="State">
                        <input wire:model="ship_from.postal_code" class="input" placeholder="ZIP" maxlength="10" aria-label="ZIP code">
                    </div>
                    <input wire:model="ship_from.phone" class="input" placeholder="Phone (carriers may require it)" aria-label="Phone">
                </div>
                @foreach (['ship_from.line1', 'ship_from.city', 'ship_from.state', 'ship_from.postal_code', 'ship_from.phone'] as $f)
                    @error($f) <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                @endforeach
            </div>

            <div class="mt-6 border-t border-line pt-4">
                <p class="text-sm font-semibold">Returns</p>
                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="ss-rwin">Return window</label>
                        <select id="ss-rwin" wire:model="return_window_days" class="input">
                            <option value="30">30 days (PanMotive minimum)</option>
                            <option value="60">60 days</option>
                            <option value="90">90 days</option>
                        </select>
                        <p class="mt-1 text-xs text-muted">Longer windows are shown on your listings and build buyer confidence.</p>
                    </div>
                    <div>
                        <label class="label" for="ss-rship">Return shipping paid by</label>
                        <select id="ss-rship" wire:model="return_shipping" class="input">
                            <option value="buyer">Buyer (except wrong or damaged parts)</option>
                            <option value="seller">Me — free returns</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="mt-4">
                <label class="label" for="ss-return">Return policy details</label>
                <textarea id="ss-return" wire:model="return_policy" rows="4" class="input" placeholder="e.g. 30-day returns on unused parts in original packaging. Buyer pays return shipping unless the part is defective."></textarea>
                @error('return_policy') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
            </div>
            <label class="mt-4 flex items-center gap-3 text-sm">
                <input type="checkbox" wire:model.live="vacation_mode" class="rounded border-line text-accent focus:ring-accent">
                <span>
                    <span class="font-medium">Vacation mode</span>
                    <span class="block text-xs text-muted">Buyers see a heads-up on your listings that dispatch is paused. Existing orders still need fulfilling.</span>
                </span>
            </label>
            @if ($vacation_mode)
                <div class="mt-3 grid gap-3 sm:grid-cols-[1fr_220px]">
                    <div>
                        <label class="label" for="ss-vacmsg">Message to buyers</label>
                        <input id="ss-vacmsg" wire:model="vacation_message" class="input" placeholder="Back and shipping on Jan 8. Thanks for your patience!">
                        <p class="mt-1 text-xs text-muted">Shown on your listings and sent once as an automatic reply to new messages.</p>
                        @error('vacation_message') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="ss-vacuntil">Back on (optional)</label>
                        <input id="ss-vacuntil" type="datetime-local" wire:model="vacation_until" class="input">
                        <p class="mt-1 text-xs text-muted">Vacation mode switches off by itself.</p>
                        @error('vacation_until') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                    </div>
                </div>
                <label class="mt-3 flex items-center gap-3 text-sm">
                    <input type="checkbox" wire:model="vacation_pause_sales" class="rounded border-line text-accent focus:ring-accent">
                    <span><span class="font-medium">Pause new orders while I'm away</span>
                        <span class="block text-xs text-muted">Listings stay visible but can't be bought until you're back — no late shipments hurting your scorecard.</span></span>
                </label>
            @endif
        </section>

        <section class="card p-6">
            <h2 class="font-bold">Payouts</h2>
            <div class="mt-3 flex flex-wrap items-center gap-3 text-sm">
                @if ($profile->stripe_account_id && $profile->stripe_onboarding_complete)
                    <span class="inline-flex items-center gap-2 text-emerald-600 dark:text-emerald-400">
                        <x-ui.icon name="check" class="w-4 h-4" /> Connected ({{ $profile->stripe_account_id }})
                    </span>
                @else
                    <span class="text-muted">Not connected yet.</span>
                    <button type="button" wire:click="connectStripe" class="btn-brand btn-sm">Connect Stripe</button>
                @endif
            </div>
        </section>

        <section class="card p-6">
            <label class="flex items-center gap-3">
                <input type="checkbox" wire:model.live="loyalty_enabled" class="rounded border-line text-accent focus:ring-accent">
                <span class="font-bold">Store loyalty points</span>
            </label>
            <p class="mt-1 text-sm text-muted">Buyers earn points on delivered orders and redeem them for PanMotive credit.</p>
            @if ($loyalty_enabled)
                <div class="mt-4 grid gap-4 sm:grid-cols-3">
                    <div>
                        <label class="label" for="lo-ppd">Points per $1 spent</label>
                        <input id="lo-ppd" type="number" wire:model="points_per_dollar" class="input">
                        @error('points_per_dollar') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="lo-cpp">Cents per point on redeem</label>
                        <input id="lo-cpp" type="number" wire:model="redeem_cents_per_point" class="input">
                    </div>
                    <div>
                        <label class="label" for="lo-min">Minimum points to redeem</label>
                        <input id="lo-min" type="number" wire:model="min_redeem" class="input">
                    </div>
                </div>
                <p class="mt-2 text-xs text-muted">Example: {{ $points_per_dollar * 50 }} points on a $50 order → worth ${{ number_format($points_per_dollar * 50 * $redeem_cents_per_point / 100, 2) }} credit.</p>
            @endif
        </section>

        <button type="submit" class="btn-accent">Save settings</button>
    </form>
</div>
