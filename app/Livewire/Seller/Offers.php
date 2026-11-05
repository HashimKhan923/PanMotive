<?php

namespace App\Livewire\Seller;

use App\Models\Offer;
use App\Services\Seller\OfferService;
use App\Support\SellerContext;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.seller')]
class Offers extends Component
{
    public string $filter = 'open';

    /** @var array<int, string> */
    public array $counter = [];

    public function mount(OfferService $offers): void
    {
        $offers->expireStale();
    }

    private function owned(int $id): Offer
    {
        return Offer::where('seller_id', auth()->id())->with('product', 'buyer')->findOrFail($id);
    }

    public function accept(int $id, OfferService $offers): void
    {
        abort_unless(SellerContext::can('orders.manage'), 403);

        try {
            $offers->accept($this->owned($id), 'seller');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->dispatch('toast', message: $e->getMessage(), kind: 'error');

            return;
        }
        $this->dispatch('toast', message: 'Offer accepted — the buyer can now buy at that price for 48 hours.');
        $this->dispatch('celebrate');
    }

    public function decline(int $id, OfferService $offers): void
    {
        abort_unless(SellerContext::can('orders.manage'), 403);

        $offers->decline($this->owned($id));
        $this->dispatch('toast', message: 'Offer declined');
    }

    public function sendCounter(int $id, OfferService $offers): void
    {
        abort_unless(SellerContext::can('orders.manage'), 403);

        $price = (float) ($this->counter[$id] ?? 0);
        $this->validate(["counter.$id" => 'required|numeric|min:0.01'], [], ["counter.$id" => 'counter price']);

        try {
            $offers->counter($this->owned($id), $price);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Show the service's reason ("must be above the offer…") under this offer's box.
            $this->addError("counter.$id", collect($e->errors())->flatten()->first());

            return;
        }
        unset($this->counter[$id]);
        $this->dispatch('toast', message: 'Counter-offer sent');
    }

    public function render()
    {
        $offers = Offer::where('seller_id', auth()->id())
            ->with('product:id,title,slug,price,sale_price', 'buyer:id,name')
            ->when($this->filter === 'open', fn ($q) => $q->whereIn('status', ['pending', 'countered']))
            ->when($this->filter === 'accepted', fn ($q) => $q->where('status', 'accepted'))
            ->when($this->filter === 'closed', fn ($q) => $q->whereIn('status', ['declined', 'expired', 'withdrawn']))
            ->latest()
            ->paginate(20);

        $openCount = Offer::where('seller_id', auth()->id())->whereIn('status', ['pending', 'countered'])->count();

        return view('livewire.seller.offers', [
            'offers' => $offers,
            'openCount' => $openCount,
            'canManage' => SellerContext::can('orders.manage'),
        ]);
    }
}
