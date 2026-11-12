<?php

namespace App\Livewire\Storefront;

use App\Models\Product;
use App\Services\Cart\CartService;
use Livewire\Component;

class AddToCartButton extends Component
{
    public Product $product;

    public bool $added = false;

    public ?int $variantId = null;

    public int $qty = 1;

    public function mount(): void
    {
        if ($this->product->hasVariants()) {
            $this->variantId = $this->product->activeVariants()->where('quantity', '>', 0)->value('id')
                ?? $this->product->activeVariants()->value('id');
        }
    }

    /** Out of stock: email me when it's back. Guests sign in first. */
    public function notifyMe()
    {
        if (! auth()->check()) {
            session(['url.intended' => route('products.show', $this->product->slug)]);

            return $this->redirectRoute('login');
        }

        \App\Models\StockAlert::updateOrCreate(
            ['user_id' => auth()->id(), 'product_id' => $this->product->id],
            ['notified_at' => null],
        );
        $this->dispatch('toast', message: 'We’ll email you as soon as it’s back in stock.');
    }

    /** Swap the PDP gallery to this variant's own photo (e.g. the blue one for "Blue") when it has one. */
    public function updatedVariantId(): void
    {
        $variant = $this->product->activeVariants()->find($this->variantId);
        $url = $variant?->imageUrl('webp');

        if ($url) {
            $this->dispatch('variant-image-changed', productId: $this->product->id, url: $url);
        }
    }

    /** Returns whether it went in — the button shows "Added" only on true. */
    public function add(CartService $cart): bool
    {
        if (! $this->product->isListed() || $this->paused()) {
            return false;
        }

        $qty = max(1, min(99, $this->qty));

        if ($this->product->hasVariants()) {
            $variant = $this->product->activeVariants()->find($this->variantId);
            if (! $variant) {
                $this->addError('variantId', 'Please choose an option.');

                return false;
            }
            if ($variant->quantity < 1) {
                $this->addError('variantId', 'That option is out of stock.');

                return false;
            }
            $cart->add($this->product->id, $qty, $variant->id);
        } else {
            if ($this->product->quantity < 1) {
                return false;
            }
            $cart->add($this->product->id, $qty);
        }

        $this->added = true;
        $this->dispatch('cart-updated', count: $cart->itemCount());

        return true;
    }

    /** The seller is away with new orders paused. */
    private function paused(): bool
    {
        return (bool) $this->product->seller?->sellerProfile?->salesPaused();
    }

    public function render()
    {
        $variants = $this->product->hasVariants()
            ? $this->product->activeVariants()->get()
            : collect();

        return view('livewire.storefront.add-to-cart-button', [
            'variants' => $variants,
            'paused' => $this->paused(),
        ]);
    }
}
