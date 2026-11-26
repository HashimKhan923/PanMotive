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

    /** Supplier catalog filters: category (with its sub-categories) and vehicle. */
    #[Url]
    public ?int $category = null;

    #[Url]
    public ?int $make = null;

    #[Url]
    public ?int $model = null;

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

    public function updatingCategory(): void
    {
        $this->resetPage();
    }

    public function updatingMake(): void
    {
        $this->model = null;
        $this->resetPage();
    }

    public function updatingModel(): void
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
        $this->reset('q', 'category', 'make', 'model');
        $this->resetPage();
    }

    public function closeSupplier(): void
    {
        $this->supplier = null;
        $this->reset('q', 'category', 'make', 'model');
        $this->resetPage();
    }

    /**
     * Add every product this supplier offers that isn't in the store yet. Small catalogs are added at
     * once; big ones in the background (you get a notification when it's done).
     */
    public function addCatalog(): void
    {
        abort_unless(SellerContext::can('products.manage'), 403);
        abort_unless($this->supplier && $this->supply()->connection($this->supplier, $this->me()->id)?->isApproved(), 403);
        $missing = $this->supply()->offered((int) $this->supplier)->whereNotIn('id', $this->addedSourceIds())->count();
        if ($missing === 0) {
            $this->dispatch('toast', message: 'You already resell everything this supplier offers');

            return;
        }
        if ($missing <= 40) {
            \App\Jobs\AddSupplierCatalog::dispatchSync($this->me()->id, (int) $this->supplier, false);
            $this->dispatch('toast', message: "{$missing} products added to your store");

            return;
        }
        \App\Jobs\AddSupplierCatalog::dispatch($this->me()->id, (int) $this->supplier);
        $this->dispatch('toast', message: "Adding {$missing} products in the background — we'll notify you when it's done");
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
        $categoryIds = $this->category
            ? \App\Models\Category::where('id', $this->category)->orWhere('parent_id', $this->category)->pluck('id')
            : null;

        return $this->supply()->offered((int) $this->supplier)
            ->when(trim($this->q) !== '', fn ($q) => app(\App\Services\Search\CatalogSearch::class)->apply($q, $this->q))
            ->when($categoryIds, fn ($q) => $q->whereIn('category_id', $categoryIds))
            ->when($this->make, fn ($q) => $q->whereHas('fitments', fn ($f) => $f->where('vehicles.vehicle_make_id', $this->make)
                ->when($this->model, fn ($m) => $m->where('vehicles.vehicle_model_id', $this->model))))
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
            // How many stores resell each product on this page.
            $data['resoldBy'] = Product::whereIn('source_product_id', $data['products']->pluck('id'))
                ->where('status', 'active')->selectRaw('source_product_id, COUNT(*) c')->groupBy('source_product_id')->pluck('c', 'source_product_id');
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
                // Filter choices: only categories and vehicles this supplier actually has parts for.
                $offered = $this->supply()->offered((int) $this->supplier);
                $catIds = (clone $offered)->distinct()->pluck('category_id')->filter();
                $data['categories'] = \App\Models\Category::whereIn('id', $catIds)
                    ->orWhereIn('id', \App\Models\Category::whereIn('id', $catIds)->whereNotNull('parent_id')->pluck('parent_id'))
                    ->orderByRaw('parent_id IS NOT NULL')->orderBy('name')->get(['id', 'name', 'parent_id']);
                $vehicleRows = \Illuminate\Support\Facades\DB::table('product_fitments')
                    ->join('vehicles', 'vehicles.id', '=', 'product_fitments.vehicle_id')
                    ->whereIn('product_fitments.product_id', (clone $offered)->select('products.id'))
                    ->distinct()->get(['vehicles.vehicle_make_id', 'vehicles.vehicle_model_id']);
                $data['makes'] = \App\Models\VehicleMake::whereIn('id', $vehicleRows->pluck('vehicle_make_id')->unique())->orderBy('name')->get(['id', 'name']);
                $data['models'] = $this->make
                    ? \App\Models\VehicleModel::whereIn('id', $vehicleRows->where('vehicle_make_id', $this->make)->pluck('vehicle_model_id')->unique())->orderBy('name')->get(['id', 'name'])
                    : collect();
                $data['missingCount'] = (clone $offered)->whereNotIn('id', $data['added'])->count();
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
