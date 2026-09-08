<div class="max-w-2xl mx-auto py-12 px-4">
    <div class="flex justify-between mb-8 text-sm">
        @foreach (['Business Info', 'Shipping', 'Get Paid'] as $i => $label)
            <div class="flex-1 text-center {{ $step === $i + 1 ? 'font-bold text-amber-600' : 'text-slate-400' }}">
                {{ $i + 1 }}. {{ $label }}
            </div>
        @endforeach
    </div>

    @if ($step === 1)
        <form wire:submit="saveBusinessInfo" class="space-y-4" data-reveal>
            <div>
                <label class="text-sm font-medium">Business Name</label>
                <input wire:model="business_name" class="w-full rounded-lg border-slate-300 mt-1">
                @error('business_name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="text-sm font-medium">Business Type</label>
                <select wire:model="business_type" class="w-full rounded-lg border-slate-300 mt-1">
                    <option value="sole_prop">Sole Proprietor</option>
                    <option value="llc">LLC</option>
                    <option value="corp">Corporation</option>
                </select>
            </div>
            <div>
                <label class="text-sm font-medium">EIN / Tax ID (optional for now)</label>
                <input wire:model="ein" class="w-full rounded-lg border-slate-300 mt-1">
            </div>
            <button class="bg-amber-500 hover:bg-amber-400 text-slate-900 font-semibold rounded-lg px-6 py-3 w-full transition">
                Continue
            </button>
        </form>
    @elseif ($step === 2)
        <form wire:submit="saveShippingSettings" class="space-y-4" data-reveal>
            <div>
                <label class="text-sm font-medium">Default Carrier</label>
                <select wire:model="shipping_default_carrier" class="w-full rounded-lg border-slate-300 mt-1">
                    <option value="usps">USPS</option>
                    <option value="ups">UPS</option>
                    <option value="fedex">FedEx</option>
                </select>
            </div>
            <div>
                <label class="text-sm font-medium">Processing Time</label>
                <select wire:model="shipping_processing_days" class="w-full rounded-lg border-slate-300 mt-1">
                    <option value="1-2">1-2 business days</option>
                    <option value="3-5">3-5 business days</option>
                </select>
            </div>
            <button class="bg-amber-500 hover:bg-amber-400 text-slate-900 font-semibold rounded-lg px-6 py-3 w-full transition">
                Continue
            </button>
        </form>
    @elseif ($step === 3)
        <div class="text-center space-y-4" data-reveal>
            <p class="text-slate-600">Last step — connect a payout account so you can get paid when orders are delivered.</p>
            <button wire:click="connectStripe" class="bg-slate-900 hover:bg-slate-800 text-white font-semibold rounded-lg px-6 py-3 transition">
                Connect Payout Account (Stripe)
            </button>
        </div>
    @endif
</div>
