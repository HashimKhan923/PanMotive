<?php

namespace App\Livewire\Storefront;

use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.storefront')]
class CartPage extends Component
{
    /**
     * Line items for the current cart.
     *
     * TODO (Phase 3): back this with a real cart store (session or DB) keyed by
     * product_id, with quantity + per-seller grouping for the split checkout.
     *
     * @var array<int, array{product_id:int, title:string, unit_price:float, quantity:int}>
     */
    public array $items = [];

    public function render()
    {
        $subtotal = collect($this->items)->sum(fn ($i) => $i['unit_price'] * $i['quantity']);

        return view('livewire.storefront.cart-page', [
            'subtotal' => $subtotal,
        ]);
    }
}
