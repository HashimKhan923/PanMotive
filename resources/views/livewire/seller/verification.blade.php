<div class="max-w-2xl">
    <h1 class="text-2xl font-bold">Identity verification</h1>
    <p class="mt-1 text-sm text-muted">US marketplace rules require us to verify every seller's business and identity. This is separate from Stripe payout onboarding.</p>

    @php
        $status = $verification->status;
        $chip = [
            'unsubmitted' => ['Not started', '!bg-surface-2 !text-muted'],
            'pending' => ['In review', '!bg-amber-500/15 !text-amber-600'],
            'approved' => ['Verified', '!bg-emerald-500/15 !text-emerald-600'],
            'rejected' => ['Action needed', '!bg-rose-500/15 !text-rose-600'],
        ][$status];
    @endphp

    <div class="card mt-6 p-6">
        <span class="pill {{ $chip[1] }}">{{ $chip[0] }}</span>
        @if ($status === 'approved')
            <p class="mt-3 text-sm">Your account is fully verified. Nothing more to do here.</p>
        @elseif ($status === 'pending')
            <p class="mt-3 text-sm">Submitted {{ $verification->submitted_at?->diffForHumans() }}. We'll email you when the review is done.</p>
        @elseif ($status === 'rejected')
            <p class="mt-3 rounded-lg bg-rose-500/10 px-4 py-2 text-sm text-rose-600">{{ $verification->rejection_reason ?: 'Some documents could not be verified. Please re-upload and resubmit.' }}</p>
        @endif
    </div>

    @if (! $isOwner && ! in_array($status, ['pending', 'approved'], true))
        <p class="card mt-6 p-6 text-sm text-muted">Only the store owner can submit identity documents. Ask them to sign in and complete this step.</p>
    @elseif (! in_array($status, ['pending', 'approved'], true))
        <form wire:submit="submit" class="card mt-6 space-y-4 p-6">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label" for="kyc-legal">Legal business name</label>
                    <input id="kyc-legal" wire:model="legalName" class="input">
                    @error('legalName') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="kyc-addr">Registered address</label>
                    <input id="kyc-addr" wire:model="registeredAddress" class="input">
                    @error('registeredAddress') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="kyc-taxtype">Tax ID type</label>
                    <select id="kyc-taxtype" wire:model="taxIdType" class="input">
                        <option value="ein">EIN (business)</option>
                        <option value="ssn_last4">SSN (sole proprietor)</option>
                    </select>
                </div>
                <div>
                    <label class="label" for="kyc-tax4">Last 4 of tax ID</label>
                    <input id="kyc-tax4" wire:model="taxIdLast4" maxlength="4" inputmode="numeric" class="input">
                    @error('taxIdLast4') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="space-y-3">
                <div>
                    <label class="label" for="kyc-biz">Business document <span class="text-muted">— EIN letter, business license, or formation docs</span></label>
                    <input id="kyc-biz" type="file" wire:model="businessDoc" class="input" accept=".pdf,.jpg,.jpeg,.png">
                    @if ($verification->business_doc_path)<p class="mt-1 text-xs text-emerald-600">A file is already on record — upload again to replace it.</p>@endif
                    @error('businessDoc') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="kyc-id">Government photo ID <span class="text-muted">— of the owner or an authorised officer</span></label>
                    <input id="kyc-id" type="file" wire:model="idDoc" class="input" accept=".pdf,.jpg,.jpeg,.png">
                    @if ($verification->id_doc_path)<p class="mt-1 text-xs text-emerald-600">A file is already on record — upload again to replace it.</p>@endif
                    @error('idDoc') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="kyc-addrproof">Proof of address <span class="text-muted">(optional) — utility bill or bank statement</span></label>
                    <input id="kyc-addrproof" type="file" wire:model="addressProof" class="input" accept=".pdf,.jpg,.jpeg,.png">
                    @error('addressProof') <p class="mt-1 text-xs text-rose-500">{{ $message }}</p> @enderror
                </div>
            </div>

            <p class="text-xs text-muted">Documents are stored privately and used only for verification. We never display your full tax ID.</p>
            <button class="btn-accent">Submit for review</button>
        </form>
    @endif
</div>
