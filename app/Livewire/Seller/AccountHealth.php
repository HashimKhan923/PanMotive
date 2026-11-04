<?php

namespace App\Livewire\Seller;

use App\Models\BuyerClaim;
use App\Models\PolicyViolation;
use App\Services\Seller\AccountHealthService;
use App\Support\SellerContext;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.seller')]
class AccountHealth extends Component
{
    public ?int $appealId = null;
    public string $appealText = '';

    public ?int $claimId = null;
    public string $claimResponse = '';

    public function startAppeal(int $id): void
    {
        abort_unless(SellerContext::can('settings.manage'), 403);
        $this->appealId = $this->ownedViolation($id)->id;
        $this->appealText = '';
    }

    public function submitAppeal(AccountHealthService $health): void
    {
        abort_unless(SellerContext::can('settings.manage'), 403);
        $this->validate(['appealText' => 'required|string|min:20|max:3000']);
        $health->submitAppeal($this->ownedViolation($this->appealId), $this->appealText);
        $this->appealId = null;
        $this->dispatch('toast', message: 'Appeal submitted for review.');
    }

    public function startResponse(int $id): void
    {
        abort_unless(SellerContext::can('returns.manage'), 403);
        $this->claimId = $this->ownedClaim($id)->id;
        $this->claimResponse = '';
    }

    public function submitResponse(AccountHealthService $health): void
    {
        abort_unless(SellerContext::can('returns.manage'), 403);
        $this->validate(['claimResponse' => 'required|string|min:10|max:3000']);
        $health->respondToClaim($this->ownedClaim($this->claimId), $this->claimResponse);
        $this->claimId = null;
        $this->dispatch('toast', message: 'Response sent. PanMotive will review if the buyer stays unsatisfied.');
    }

    public function refundClaim(int $id, AccountHealthService $health): void
    {
        abort_unless(SellerContext::can('returns.manage'), 403);

        try {
            $amount = $health->resolveWithRefund($this->ownedClaim($id), auth()->user());
        } catch (\RuntimeException $e) {
            $this->dispatch('toast', message: $e->getMessage());

            return;
        }

        $this->dispatch('toast', message: 'Refunded $'.number_format($amount, 2).' — claim closed and it won\'t count against your account.');
    }

    private function ownedViolation(int $id): PolicyViolation
    {
        return PolicyViolation::where('seller_id', auth()->id())->findOrFail($id);
    }

    private function ownedClaim(int $id): BuyerClaim
    {
        return BuyerClaim::where('seller_id', auth()->id())->findOrFail($id);
    }

    public function render()
    {
        $sellerId = auth()->id();

        return view('livewire.seller.account-health', [
            'standing' => app(AccountHealthService::class)->standing(auth()->user()),
            'violations' => PolicyViolation::where('seller_id', $sellerId)->latest()->get(),
            'claims' => BuyerClaim::where('seller_id', $sellerId)
                ->with('orderItem.order:id,order_number', 'buyer:id,name')
                ->orderByRaw("CASE status WHEN 'escalated' THEN 0 WHEN 'open' THEN 1 WHEN 'seller_responded' THEN 2 ELSE 3 END")
                ->latest()->get(),
        ]);
    }
}
