<?php

namespace App\Livewire\Storefront;

use App\Models\Offer;
use App\Models\Product;
use App\Services\Cart\CartService;
use App\Services\Seller\OfferService;
use Livewire\Attributes\Validate;
use Livewire\Component;

class MakeOffer extends Component
{
    public Product $product;

    public bool $open = false;

    #[Validate('required|numeric|min:0.01')]
    public ?float $price = null;

    #[Validate('required|integer|min:1|max:99')]
    public int $quantity = 1;

    #[Validate('nullable|string|max:300')]
    public string $message = '';

    public function mount(): void
    {
        $this->price = round($this->product->effectivePrice() * 0.9, 2);
    }

    public function getExistingOfferProperty(): ?Offer
    {
        if (! auth()->check()) {
            return null;
        }

        return Offer::where('product_id', $this->product->id)
            ->where('buyer_id', auth()->id())
            ->where(fn ($q) => $q->whereIn('status', ['pending', 'countered'])
                // An accepted deal only shows while its price hold is live and the code is unused.
                ->orWhere(fn ($a) => $a->where('status', 'accepted')->where('expires_at', '>', now())
                    ->whereHas('coupon', fn ($c) => $c->where('times_used', 0))))
            ->latest()->first();
    }

    public function submit(OfferService $offers): void
    {
        abort_unless(auth()->check(), 403);
        $this->validate();

        try {
            $offer = $offers->create(auth()->user(), $this->product, (float) $this->price, $this->quantity, $this->message ?: null);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // The service reports under its own field name — show it on this form's price box.
            $this->addError('price', collect($e->errors())->flatten()->first());

            return;
        }

        $this->reset('open', 'message');
        $this->dispatch('toast', message: $offer->status === 'accepted'
            ? 'Offer accepted instantly! Buy it now at your price.'
            : 'Offer sent — the seller has 48 hours to respond.');
    }

    private function myOffer(): Offer
    {
        abort_unless(auth()->check(), 403);

        return Offer::where('product_id', $this->product->id)->where('buyer_id', auth()->id())
            ->whereIn('status', ['countered', 'accepted'])->latest()->firstOrFail();
    }

    public function acceptCounter(OfferService $offers): void
    {
        $offer = $this->myOffer();
        try {
            $offers->accept($offer, 'buyer');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->dispatch('toast', message: $e->getMessage(), kind: 'error');

            return;
        }
        $this->dispatch('toast', message: 'Deal! Buy it now at the agreed price.');
    }

    public function declineCounter(OfferService $offers): void
    {
        $offers->declineCounter($this->myOffer());
        $this->dispatch('toast', message: 'Counter-offer declined.');
    }

    /** Put the agreed quantity in the cart with the offer code applied, and go to checkout. */
    public function buyNow(CartService $cart)
    {
        $offer = $this->myOffer();
        abort_unless($offer->status === 'accepted', 422);

        $inCart = (int) collect($cart->lines())->filter(fn ($l) => $l['product']->id === $this->product->id)->sum('quantity');
        if ($inCart < $offer->quantity) {
            $cart->add($this->product->id, $offer->quantity - $inCart);
        }
        session(['checkout.coupon_code' => $offer->coupon_code]);

        return redirect()->route('checkout.index');
    }

    public function render()
    {
        return view('livewire.storefront.make-offer');
    }
}
