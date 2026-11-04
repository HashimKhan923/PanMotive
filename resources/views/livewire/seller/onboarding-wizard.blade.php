<div class="mx-auto max-w-2xl py-10">
    <div class="text-center">
        <span class="grid mx-auto h-11 w-11 place-items-center rounded-xl bg-accent text-accent-fg">
            <x-ui.icon name="store" class="w-6 h-6" />
        </span>
        <h1 class="mt-3 text-2xl font-bold">Become a PanMotive seller</h1>
        <p class="mt-1 text-sm text-muted">Three quick steps and you can start listing parts.</p>
    </div>

    <div class="mt-8 flex items-center">
        @foreach (['Business', 'Shipping', 'Get paid'] as $i => $label)
            <div class="flex flex-1 items-center {{ $i < 2 ? '' : 'flex-none' }}">
                <div class="flex items-center gap-2">
                    <span class="grid h-7 w-7 place-items-center rounded-full text-xs font-bold
                                 {{ $step > $i + 1 ? 'bg-accent text-accent-fg' : ($step === $i + 1 ? 'bg-brand text-brand-fg' : 'bg-surface-2 text-muted') }}">
                        @if ($step > $i + 1) <x-ui.icon name="check" class="w-3.5 h-3.5" /> @else {{ $i + 1 }} @endif
                    </span>
                    <span class="text-sm font-medium {{ $step === $i + 1 ? 'text-fg' : 'text-muted' }}">{{ $label }}</span>
                </div>
                @if ($i < 2) <div class="mx-3 h-px flex-1 bg-line"></div> @endif
            </div>
        @endforeach
    </div>

    <div class="card mt-8 p-6">
        @if ($step === 1)
            <form wire:submit="saveBusinessInfo" class="space-y-4">
                <div>
                    <label class="label" for="ob-name">Business name</label>
                    <input id="ob-name" wire:model="business_name" class="input">
                    @error('business_name') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="ob-type">Business type</label>
                    <select id="ob-type" wire:model="business_type" class="input">
                        <option value="sole_prop">Sole proprietor</option>
                        <option value="llc">LLC</option>
                        <option value="corp">Corporation</option>
                    </select>
                </div>
                <div>
                    <label class="label" for="ob-ein">EIN / Tax ID <span class="text-muted">(optional for now)</span></label>
                    <input id="ob-ein" wire:model="ein" class="input">
                    @error('ein') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
                <button class="btn-accent w-full">Continue</button>
            </form>
        @elseif ($step === 2)
            <form wire:submit="saveShippingSettings" class="space-y-4">
                <div>
                    <label class="label" for="ob-carrier">Default carrier</label>
                    <select id="ob-carrier" wire:model="shipping_default_carrier" class="input">
                        <option value="usps">USPS</option>
                        <option value="ups">UPS</option>
                        <option value="fedex">FedEx</option>
                    </select>
                </div>
                <div>
                    <label class="label" for="ob-proc">Processing time</label>
                    <select id="ob-proc" wire:model="shipping_processing_days" class="input">
                        <option value="1-2">1–2 business days</option>
                        <option value="3-5">3–5 business days</option>
                    </select>
                </div>
                <button class="btn-accent w-full">Continue</button>
            </form>
        @elseif ($step === 3)
            <div class="space-y-4 text-center">
                <x-ui.icon name="dollar" class="mx-auto w-10 h-10 text-accent" />
                <p class="text-sm text-muted">Last step — connect a payout account so you get paid when your orders are delivered. Funds are held in escrow until then.</p>
                <button wire:click="connectStripe" class="btn-brand">Connect payout account (Stripe)</button>
                <p class="text-xs text-muted">You can also do this later from Store settings.</p>
            </div>
        @endif
    </div>
</div>
