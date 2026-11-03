<?php

namespace App\Livewire\Seller;

use App\Models\SellerVerification;
use App\Support\SellerContext;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.seller')]
class Verification extends Component
{
    use WithFileUploads;

    #[Validate('required|string|max:200')]
    public string $legalName = '';

    #[Validate('required|string|max:255')]
    public string $registeredAddress = '';

    #[Validate('required|in:ein,ssn_last4')]
    public string $taxIdType = 'ein';

    #[Validate('required|digits:4')]
    public string $taxIdLast4 = '';

    public $businessDoc;
    public $idDoc;
    public $addressProof;

    public function mount(): void
    {
        $v = $this->record();
        $this->legalName = $v->legal_name ?? (auth()->user()->sellerProfile->business_name ?? '');
        $this->registeredAddress = $v->registered_address ?? '';
        $this->taxIdType = $v->tax_id_type ?? 'ein';
        $this->taxIdLast4 = $v->tax_id_last4 ?? '';
    }

    private function record(): SellerVerification
    {
        return SellerVerification::firstOrCreate(['seller_id' => auth()->id()]);
    }

    public function submit(): void
    {
        // These are the owner's own identity documents — staff can see the status, not submit them.
        abort_unless(SellerContext::actor()?->id === auth()->id(), 403, 'Only the store owner can submit identity verification.');
        $v = $this->record();

        abort_if(in_array($v->status, ['pending', 'approved'], true), 422);

        $this->validate([
            'businessDoc' => ($v->business_doc_path ? 'nullable' : 'required').'|file|mimes:pdf,jpg,jpeg,png|max:8192',
            'idDoc' => ($v->id_doc_path ? 'nullable' : 'required').'|file|mimes:pdf,jpg,jpeg,png|max:8192',
            'addressProof' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:8192',
        ]);
        $this->validate();

        $dir = 'seller-verification/'.auth()->id();

        $v->update([
            'legal_name' => $this->legalName,
            'registered_address' => $this->registeredAddress,
            'tax_id_type' => $this->taxIdType,
            'tax_id_last4' => $this->taxIdLast4,
            'business_doc_path' => $this->businessDoc ? $this->businessDoc->store($dir, 'local') : $v->business_doc_path,
            'id_doc_path' => $this->idDoc ? $this->idDoc->store($dir, 'local') : $v->id_doc_path,
            'address_proof_path' => $this->addressProof ? $this->addressProof->store($dir, 'local') : $v->address_proof_path,
            'status' => 'pending',
            'rejection_reason' => null,
            'submitted_at' => now(),
        ]);

        \App\Models\AdminAlert::create([
            'type' => 'kyc_submitted',
            'message' => "Seller \"{$this->legalName}\" submitted identity verification for review.",
        ]);

        $this->reset('businessDoc', 'idDoc', 'addressProof');
        $this->dispatch('toast', message: 'Submitted for review. We usually respond within 2 business days.');
    }

    public function render()
    {
        return view('livewire.seller.verification', [
            'verification' => $this->record(),
            'isOwner' => SellerContext::actor()?->id === auth()->id(),
        ]);
    }
}
