<?php

namespace App\Livewire\Seller;

use App\Models\Affiliate;
use App\Models\AffiliateProgram;
use App\Services\Marketing\AffiliateService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.seller')]
class Affiliates extends Component
{
    public bool $isActive = false;

    #[Validate('required|numeric|min:1|max:40')]
    public float $commissionPct = 8;

    #[Validate('required|integer|min:1|max:90')]
    public int $cookieDays = 30;

    public bool $autoApprove = true;

    #[Validate('nullable|string|max:2000')]
    public string $terms = '';

    public function mount(): void
    {
        $p = $this->program();
        $this->isActive = (bool) $p->is_active;
        $this->commissionPct = (float) ($p->commission_pct ?? 8);
        $this->cookieDays = (int) ($p->cookie_days ?? 30);
        $this->autoApprove = (bool) ($p->auto_approve ?? true);
        $this->terms = $p->terms ?? '';
    }

    private function program(): AffiliateProgram
    {
        return AffiliateProgram::firstOrCreate(['seller_id' => auth()->id()]);
    }

    public function save(): void
    {
        $this->validate();
        $this->program()->update([
            'is_active' => $this->isActive,
            'commission_pct' => $this->commissionPct,
            'cookie_days' => $this->cookieDays,
            'auto_approve' => $this->autoApprove,
            'terms' => $this->terms ?: null,
        ]);
        $this->dispatch('toast', message: 'Affiliate program saved');
    }

    public function decide(int $affiliateId, string $status): void
    {
        abort_unless(in_array($status, ['approved', 'rejected'], true), 422);
        Affiliate::where('affiliate_program_id', $this->program()->id)
            ->whereKey($affiliateId)->update(['status' => $status]);
    }

    public function pay(int $affiliateId, AffiliateService $service): void
    {
        $affiliate = Affiliate::where('affiliate_program_id', $this->program()->id)->with('program.seller.sellerProfile')->findOrFail($affiliateId);
        $paid = $service->payOut($affiliate);
        $this->dispatch('toast', message: $paid > 0 ? "Paid \${$paid} to {$affiliate->name}." : 'Nothing due.');
    }

    public function render()
    {
        $program = $this->program()->load('seller.sellerProfile');

        return view('livewire.seller.affiliates', [
            'program' => $program,
            'affiliates' => Affiliate::where('affiliate_program_id', $program->id)->latest()->get(),
        ]);
    }
}
