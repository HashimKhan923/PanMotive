<?php

namespace App\Livewire\Seller;

use App\Models\Boost;
use App\Models\Product;
use App\Models\PromoLink;
use App\Models\User;
use App\Notifications\SellerAnnouncement;
use App\Services\Seller\BoostService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts.seller')]
class Marketing extends Component
{
    #[Url]
    public string $tab = 'boost';

    // Boost form
    public ?int $boostProductId = null;
    public string $boostPlacement = 'search';
    public int $boostDays = 7;

    // Announcement
    #[Validate('required|string|max:180')]
    public string $announcement = '';
    public int $announcementDays = 7;

    // Promo link
    public ?int $linkProductId = null;
    public string $linkLabel = '';

    public function mount(): void
    {
        $this->announcement = auth()->user()->sellerProfile->announcement ?? '';
    }

    public function getQuoteProperty(BoostService $boosts): float
    {
        return $boosts->quote($this->boostPlacement, $this->boostDays);
    }

    public function startBoost(BoostService $boosts): void
    {
        $this->validate(['boostProductId' => 'required|integer', 'boostDays' => 'integer|min:1|max:30']);
        $product = Product::where('seller_id', auth()->id())->findOrFail($this->boostProductId);

        $boosts->start(auth()->user(), $product, $this->boostPlacement, $this->boostDays);
        $this->reset('boostProductId');
        $this->dispatch('toast', message: 'Boost started — your listing now gets priority placement.');
        $this->dispatch('celebrate');
    }

    public function cancelBoost(int $id, BoostService $boosts): void
    {
        $boost = Boost::where('seller_id', auth()->id())->findOrFail($id);
        $boosts->cancel($boost);
        $this->dispatch('toast', message: 'Boost cancelled — unused days refunded to ad credit.');
    }

    public function grantTestCredit(BoostService $boosts): void
    {
        // Free credit is a development convenience only — never on the live site.
        abort_unless(app()->environment('local', 'testing'), 403);
        $boosts->grantCredit(auth()->user(), 25.00, 'Demo ad credit', 'promo_grant');
        $this->dispatch('toast', message: '$25.00 test credit added.');
    }

    public function publishAnnouncement(): void
    {
        $this->validate();
        $profile = auth()->user()->sellerProfile;
        $profile->update([
            'announcement' => $this->announcement,
            'announcement_until' => now()->addDays(max(1, $this->announcementDays)),
        ]);

        // Followers get pinged at most once per cooldown — fixing a typo shouldn't notify everyone again.
        $cooldown = (int) config('marketplace.marketing.announcement_cooldown_hours', 24);
        if ($profile->announcement_notified_at && $profile->announcement_notified_at->gt(now()->subHours($cooldown))) {
            $this->dispatch('toast', message: 'Announcement updated on your store. Followers were already notified in the last '.$cooldown.' hours.');

            return;
        }

        $followers = User::whereHas('followedSellers', fn ($q) => $q->where('seller_id', auth()->id()))
            ->whereNotIn('id', \App\Models\SellerBlockedBuyer::where('seller_id', auth()->id())->select('buyer_id'))
            ->get();
        foreach ($followers as $f) {
            $f->notify(new SellerAnnouncement($profile, $this->announcement));
        }
        $profile->forceFill(['announcement_notified_at' => now()])->save();

        $this->dispatch('toast', message: 'Announcement published'.($followers->count() ? " to {$followers->count()} followers." : '.'));
    }

    public function clearAnnouncement(): void
    {
        auth()->user()->sellerProfile->update(['announcement' => null, 'announcement_until' => null]);
        $this->announcement = '';
        $this->dispatch('toast', message: 'Announcement cleared');
    }

    public function createLink(): void
    {
        $productId = $this->linkProductId
            ? Product::where('seller_id', auth()->id())->whereKey($this->linkProductId)->value('id')
            : null;

        PromoLink::create([
            'seller_id' => auth()->id(),
            'product_id' => $productId,
            'label' => $this->linkLabel ?: null,
        ]);

        $this->reset('linkProductId', 'linkLabel');
        $this->dispatch('toast', message: 'Tracked link created');
    }

    public function deleteLink(int $id): void
    {
        PromoLink::where('seller_id', auth()->id())->whereKey($id)->delete();
    }

    public function render()
    {
        $sellerId = auth()->id();
        $referralService = app(\App\Services\Seller\SellerReferralService::class);

        return view('livewire.seller.marketing', [
            'profile' => auth()->user()->sellerProfile,
            'products' => Product::where('seller_id', $sellerId)->where('status', 'active')->orderBy('title')->get(['id', 'title']),
            'boosts' => Boost::where('seller_id', $sellerId)->with('product:id,title,slug')->latest()->get(),
            // Social studio makes its own per-post links; they're reported there, not here.
            'links' => PromoLink::where('seller_id', $sellerId)
                ->whereNotIn('id', \App\Models\SocialPostTarget::whereNotNull('promo_link_id')->select('promo_link_id'))
                ->with('product:id,title')->latest()->get(),
            'followerCount' => User::whereHas('followedSellers', fn ($q) => $q->where('seller_id', $sellerId))->count(),
            'rates' => BoostService::RATES,
            'referralLink' => $referralService->linkFor(auth()->user()),
            'referrals' => \App\Models\SellerReferral::where('referrer_id', $sellerId)->whereNotNull('referred_id')
                ->with('referred:id,name')->latest()->get(),
        ]);
    }
}
