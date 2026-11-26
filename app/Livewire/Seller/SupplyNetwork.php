<?php

namespace App\Livewire\Seller;

use App\Enums\OrderStatus;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SupplyConnection;
use App\Models\User;
use App\Services\Seller\SupplyNetworkService;
use App\Support\SellerContext;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Supply Network (seller portal): offer your products to other stores, approve resellers, find
 * suppliers, add their products to your store, and see what you've earned reselling.
 */
#[Layout('layouts.seller')]
#[Title('Supply Network')]
class SupplyNetwork extends Component
{
    use WithPagination;

    #[Url]
    public string $tab = 'find';

    #[Url]
    public ?int $supplier = null;

    #[Url]
    public string $q = '';

    // supplier settings
    public bool $enabled = false;

    public float $margin = 20;

    public bool $autoApprove = false;

    public string $note = '';

    public string $excludeSearch = '';

    /** @var array<int, float> connection id => margin being edited */
    public array $margins = [];

    public string $requestMessage = '';

    public function mount(): void
    {
        $p = $this->me()->sellerProfile;
        $this->enabled = (bool) $p->supply_enabled;
        $this->margin = (float) $p->supply_margin_pct;
        $this->autoApprove = (bool) $p->supply_auto_approve;
        $this->note = (string) $p->supply_note;
        if (! in_array($this->tab, ['offer', 'resellers', 'find', 'listings', 'sales'], true)) {
            $this->tab = 'find';
        }
    }

    private function me(): User
    {
        return SellerContext::owner();
    }

    private function supply(): SupplyNetworkService
    {
        return app(SupplyNetworkService::class);
    }

    public function updatingQ(): void
    {
        $this->resetPage();
    }

    public function updatingTab(): void
    {
        $this->resetPage();
    }

    // ------------------------------------------------------------ supplier side

    public function saveSettings(): void
    {
        abort_unless(SellerContext::can('products.manage'), 403);
        $this->validate([
            'margin' => 'required|numeric|min:'.SupplyNetworkService::MIN_MARGIN.'|max:'.SupplyNetworkService::MAX_MARGIN,
            'note' => 'nullable|string|max:500',
        ], [
            'margin.min' => 'Offer resellers at least '.(int) SupplyNetworkService::MIN_MARGIN.'%, so reselling is worth their while.',
            'margin.max' => 'The margin can be at most '.(int) SupplyNetworkService::MAX_MARGIN.'%.',
        ]);
        $this->me()->sellerProfile->update([
            'supply_enabled' => $this->enabled,
            'supply_margin_pct' => $this->margin,
            'supply_auto_approve' => $this->autoApprove,
            'supply_note' => trim($this->note) ?: null,
        ]);
        $this->dispatch('toast', message: $this->enabled ? 'Your products are offered to resellers' : 'Supply Network is off — resold copies are no longer for sale');
    }

    public function toggleExcluded(int $productId): void
    {
        abort_unless(SellerContext::can('products.manage'), 403);
        $p = Product::where('seller_id', $this->me()->id)->whereNull('source_product_id')->findOrFail($productId);
        $p->update(['supply_excluded' => ! $p->supply_excluded]);
    }

    public function approve(int $id): void
    {
        abort_unless(SellerContext::can('products.manage'), 403);
        $conn = $this->mine($id, asSupplier: true);
        $pct = (float) ($this->margins[$id] ?? $conn->margin_pct);
        $this->supply()->approve($conn, $pct);
        $this->dispatch('toast', message: 'Reseller approved');
    }

    public function decline(int $id): void
    {
        abort_unless(SellerContext::can('products.manage'), 403);
        $this->supply()->decline($this->mine($id, asSupplier: true));
        $this->dispatch('toast', message: 'Request declined');
    }

    public function saveMargin(int $id): void
    {
        abort_unless(SellerContext::can('products.manage'), 403);
        $conn = $this->mine($id, asSupplier: true);
        $this->supply()->setMargin($conn, (float) ($this->margins[$id] ?? $conn->margin_pct));
        $this->dispatch('toast', message: 'Margin updated — applies to new orders');
    }

    public function endConnection(int $id): void
    {
        abort_unless(SellerContext::can('products.manage'), 403);
        $conn = SupplyConnection::whereKey($id)->where(fn ($q) => $q->where('supplier_id', $this->me()->id)->orWhere('reseller_id', $this->me()->id))->firstOrFail();
        $this->supply()->end($conn, $this->me());
        $this->dispatch('toast', message: 'Partnership ended');
    }

    private function mine(int $id, bool $asSupplier): SupplyConnection
    {
        return SupplyConnection::whereKey($id)->where($asSupplier ? 'supplier_id' : 'reseller_id', $this->me()->id)->firstOrFail();
    }

    // ------------------------------------------------------------ reseller side

    public function openSupplier(int $id): void
    {
        $this->supplier = $id;
        $this->q = '';
        $this->resetPage();
    }

    public function closeSupplier(): void
    {
        $this->supplier = null;
        $this->q = '';
        $this->resetPage();
    }

    public function requestConnection(int $supplierId): void
    {
        abort_unless(SellerContext::can('products.manage'), 403);
        $this->validate(['requestMessage' => 'nullable|string|max:500']);
        $conn = $this->supply()->request($this->me(), User::findOrFail($supplierId), $this->requestMessage);
        $this->requestMessage = '';
        $this->dispatch('toast', message: $conn->isApproved() ? 'Approved — you can add their products now' : 'Request sent — we\'ll let you know when they answer');
    }

    public function add(int $productId): void
    {
        abort_unless(SellerContext::can('products.manage'), 403);
        $this->supply()->addToStore($this->me(), Product::findOrFail($productId));
        $this->dispatch('toast', message: 'Added to your store');
    }

    public function addAll(): void
    {
        abort_unless(SellerContext::can('products.manage'), 403);
        abort_unless($this->supplier && $this->supply()->connection($this->supplier, $this->me()->id)?->isApproved(), 403);
        $n = 0;
        foreach ($this->catalog()->getCollection() as $p) {
            if (! in_array($p->id, $this->addedSourceIds(), true)) {
                $this->supply()->addToStore($this->me(), $p);
                $n++;
            }
        }
        $this->dispatch('toast', message: $n ? "{$n} products added to your store" : 'Everything on this page is already in your store');
    }

    public function toggleListing(int $productId): void
    {
        abort_unless(SellerContext::can('products.manage'), 403);
        $copy = Product::where('seller_id', $this->me()->id)->whereNotNull('source_product_id')->findOrFail($productId);
        $copy->update(['status' => $copy->status === 'active' ? 'archived' : 'active']);
    }

    public function removeListing(int $productId): void
    {
        abort_unless(SellerContext::can('products.manage'), 403);
        $this->supply()->removeFromStore(Product::where('seller_id', $this->me()->id)->whereNotNull('source_product_id')->findOrFail($productId));
        $this->dispatch('toast', message: 'Removed from your store');
    }

    /** @return list<int> supplier products this store already resells */
    private function addedSourceIds(): array
    {
        return once(fn () => Product::where('seller_id', $this->me()->id)->whereNotNull('source_product_id')->pluck('source_product_id')->all());
    }

    private function catalog()
    {
        return $this->supply()->offered((int) $this->supplier)
            ->when(trim($this->q) !== '', fn ($q) => app(\App\Services\Search\CatalogSearch::class)->apply($q, $this->q))
            ->with('category')
            ->latest('id')
            ->paginate(24);
    }

    public function render()
    {
        $me = $this->me();
        $data = ['me' => $me];

        if ($this->tab === 'offer') {
            $data['products'] = Product::where('seller_id', $me->id)->whereNull('source_product_id')->where('status', 'active')
                ->when(trim($this->excludeSearch) !== '', fn ($q) => $q->where('title', 'like', '%'.trim($this->excludeSearch).'%'))
                ->orderBy('title')->paginate(20, pageName: 'offerPage');
            $data['offeredCount'] = $this->supply()->offered($me->id)->count();
        }

        if ($this->tab === 'resellers') {
            $data['connections'] = SupplyConnection::where('supplier_id', $me->id)->with('reseller.sellerProfile')
                ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")->latest()->get();
            $data['resellerStats'] = OrderItem::where('seller_id', $me->id)->whereNotNull('reseller_id')
                ->whereNotIn('status', ['placed', 'cancelled'])
                ->selectRaw('reseller_id, COUNT(*) as orders, SUM(line_total) as sales, SUM(reseller_margin) as margin')
                ->groupBy('reseller_id')->get()->keyBy('reseller_id');
            foreach ($data['connections'] as $c) {
                $this->margins[$c->id] ??= (float) $c->margin_pct;
            }
        }

        if ($this->tab === 'find') {
            $data['myConnections'] = SupplyConnection::where('reseller_id', $me->id)->get()->keyBy('supplier_id');
            if ($this->supplier) {
                $data['supplierProfile'] = \App\Models\SellerProfile::where('user_id', $this->supplier)->where('supply_enabled', true)->firstOrFail();
                $data['catalog'] = $this->catalog();
                $data['added'] = $this->addedSourceIds();
                $data['connection'] = $data['myConnections'][$this->supplier] ?? null;
            } else {
                $data['suppliers'] = $this->supply()->suppliers($me->id)
                    ->when(trim($this->q) !== '', fn ($q) => $q->where('business_name', 'like', '%'.trim($this->q).'%'))
                    ->orderBy('business_name')->paginate(18);
                $ids = $data['suppliers']->pluck('user_id');
                $data['supplierCounts'] = Product::listed()->original()->whereIn('seller_id', $ids)->where('supply_excluded', false)
                    ->selectRaw('seller_id, COUNT(*) c')->groupBy('seller_id')->pluck('c', 'seller_id');
            }
        }

        if ($this->tab === 'listings') {
            $data['listings'] = Product::where('seller_id', $me->id)->whereNotNull('source_product_id')
                ->with('source.seller.sellerProfile')->latest('id')->paginate(24, pageName: 'listPage');
            $data['marginBySupplier'] = SupplyConnection::where('reseller_id', $me->id)->pluck('margin_pct', 'supplier_id');
        }

        if ($this->tab === 'sales') {
            $data['sales'] = OrderItem::where('reseller_id', $me->id)->whereNot('status', OrderStatus::Placed->value)
                ->with('order:id,order_number,created_at', 'seller.sellerProfile')->latest('id')->paginate(25, pageName: 'salesPage');
            $base = OrderItem::where('reseller_id', $me->id)->whereNotIn('status', ['placed', 'cancelled', 'refunded']);
            $data['totals'] = [
                'earned' => (float) (clone $base)->whereNotNull('reseller_paid_at')->sum('reseller_margin'),
                'pending' => (float) (clone $base)->whereNull('reseller_paid_at')->sum('reseller_margin'),
                'orders' => (clone $base)->count(),
            ];
        }

        $data['pendingRequests'] = SupplyConnection::where('supplier_id', $me->id)->where('status', 'pending')->count();

        return view('livewire.seller.supply-network', $data);
    }
}
