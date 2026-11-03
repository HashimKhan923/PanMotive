<?php

namespace App\Livewire\Seller;

use App\Models\AdCreditPurchase;
use App\Models\AdTransaction;
use App\Services\Payments\AdCreditGateway;
use App\Services\Seller\AdCreditPurchaseService;
use App\Services\Seller\BoostService;
use App\Support\SellerContext;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

/** Ad wallet: buy prepaid ad credit by card and see every top-up and charge. */
#[Layout('layouts.seller')]
class AdCredit extends Component
{
    use WithPagination;

    public float $amount = 50;

    public function pick(int $amount): void
    {
        $this->amount = $amount;
        $this->resetErrorBag();
    }

    public function buy(AdCreditPurchaseService $purchases)
    {
        abort_unless(SellerContext::can('marketing'), 403);

        $this->validate([
            'amount' => 'required|numeric|min:'.AdCreditPurchaseService::MIN.'|max:'.AdCreditPurchaseService::MAX,
        ], [
            'amount.min' => 'The minimum top-up is $'.AdCreditPurchaseService::MIN.'.',
            'amount.max' => 'The maximum single top-up is $'.number_format(AdCreditPurchaseService::MAX).'.',
        ]);

        try {
            $url = $purchases->start(auth()->user(), SellerContext::actor(), (float) $this->amount);
        } catch (RuntimeException $e) {
            $this->addError('amount', $e->getMessage());

            return null;
        } catch (\Throwable $e) {
            report($e);
            $this->addError('amount', 'We couldn\'t reach the payment provider. Please try again in a moment.');

            return null;
        }

        return redirect()->away($url);
    }

    /** Local development only — free credit so ads can be tested without a card. */
    public function grantTestCredit(BoostService $boosts): void
    {
        abort_unless(app()->environment('local', 'testing'), 403);
        $boosts->grantCredit(auth()->user(), 25.00, 'Test ad credit (development only)', 'promo_grant');
        $this->dispatch('toast', message: '$25.00 test credit added.');
    }

    public function render(AdCreditGateway $gateway)
    {
        $sellerId = auth()->id();
        $balance = (float) auth()->user()->sellerProfile->ad_credit;

        // Rough runway: average daily ad spend over the last 14 days.
        $spent14 = (float) abs(AdTransaction::where('seller_id', $sellerId)->where('type', 'boost_spend')
            ->where('created_at', '>=', now()->subDays(14))->sum('amount'));
        $dailyBurn = $spent14 / 14;

        return view('livewire.seller.ad-credit', [
            'balance' => $balance,
            'dailyBurn' => round($dailyBurn, 2),
            'daysLeft' => $dailyBurn > 0 ? (int) floor($balance / $dailyBurn) : null,
            'packages' => AdCreditPurchaseService::PACKAGES,
            'paymentsReady' => $gateway->isConfigured(),
            'canTestCredit' => app()->environment('local'),
            // id breaks ties so same-second entries stay in true order (balance reads correctly).
            'transactions' => AdTransaction::where('seller_id', $sellerId)->latest()->orderByDesc('id')->paginate(20),
            'totals' => [
                'purchased' => (float) AdCreditPurchase::where('seller_id', $sellerId)->where('status', 'paid')->sum('amount'),
                'spent_30' => (float) abs(AdTransaction::where('seller_id', $sellerId)->where('type', 'boost_spend')
                    ->where('created_at', '>=', now()->subDays(30))->sum('amount')),
            ],
        ]);
    }
}
