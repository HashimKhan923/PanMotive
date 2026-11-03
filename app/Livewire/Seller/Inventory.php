<?php

namespace App\Livewire\Seller;

use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Services\Seller\InventoryService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.seller')]
class Inventory extends Component
{
    use WithPagination;

    #[Url]
    public string $tab = 'levels';

    #[Url]
    public string $q = '';

    // new / edited location
    public ?int $locationId = null;
    public string $locName = '';
    public string $locCode = '';

    // adjust modal — for a listing, or one option (variant) of it
    public ?int $adjustProductId = null;
    public ?int $adjustVariantId = null;
    public string $adjustMode = 'change'; // change | set
    public int $adjustDelta = 0;
    public ?int $adjustTarget = null;
    public string $adjustReason = 'restock';
    public string $adjustNote = '';
    public ?int $adjustLocationId = null;

    // transfer modal
    public ?int $transferProductId = null;
    public ?int $transferVariantId = null;
    public ?int $transferFrom = null;
    public ?int $transferTo = null;
    public int $transferQty = 1;

    public function updating($name): void
    {
        if (in_array($name, ['tab', 'q'], true)) {
            $this->resetPage();
            $this->resetPage('movesPage');
        }
    }

    // ---------------- locations ----------------

    public function saveLocation(): void
    {
        $this->validate([
            'locName' => 'required|string|max:80',
            'locCode' => 'nullable|string|max:32',
        ]);

        $data = ['seller_id' => auth()->id(), 'name' => $this->locName, 'code' => $this->locCode ?: null];

        if ($this->locationId) {
            InventoryLocation::where('seller_id', auth()->id())->whereKey($this->locationId)->update($data);
        } else {
            $first = ! InventoryLocation::where('seller_id', auth()->id())->exists();
            InventoryLocation::create($data + ['is_default' => $first]);
        }

        $this->reset('locationId', 'locName', 'locCode');
        $this->dispatch('toast', message: 'Location saved.');
    }

    public function editLocation(int $id): void
    {
        $loc = InventoryLocation::where('seller_id', auth()->id())->findOrFail($id);
        $this->locationId = $loc->id;
        $this->locName = $loc->name;
        $this->locCode = $loc->code ?? '';
    }

    public function makeLocationDefault(int $id): void
    {
        InventoryLocation::where('seller_id', auth()->id())->findOrFail($id);
        InventoryLocation::where('seller_id', auth()->id())->update(['is_default' => false]);
        InventoryLocation::where('seller_id', auth()->id())->whereKey($id)->update(['is_default' => true]);
        $this->dispatch('toast', message: 'Default location updated.');
    }

    /** Only an empty, non-default location can be removed — stock is never silently lost. */
    public function deleteLocation(int $id): void
    {
        $loc = InventoryLocation::where('seller_id', auth()->id())->findOrFail($id);
        if ($loc->is_default) {
            $this->dispatch('toast', message: 'Make another location the default first.', kind: 'error');

            return;
        }
        if (ProductStock::where('inventory_location_id', $loc->id)->where('quantity', '>', 0)->exists()) {
            $this->dispatch('toast', message: 'Move its stock to another location before deleting it.', kind: 'error');

            return;
        }

        ProductStock::where('inventory_location_id', $loc->id)->delete();
        $loc->delete();
        $this->dispatch('toast', message: 'Location deleted.');
    }

    // ---------------- adjust ----------------

    public function openAdjust(int $productId, ?int $variantId = null): void
    {
        $product = $this->ownedProduct($productId);
        $variant = $variantId ? $this->ownedVariant($product, $variantId) : null;

        $this->adjustProductId = $product->id;
        $this->adjustVariantId = $variant?->id;
        $this->adjustMode = 'change';
        $this->adjustDelta = 0;
        $this->adjustTarget = $variant ? (int) $variant->quantity : (int) $product->quantity;
        $this->adjustReason = 'restock';
        $this->adjustNote = '';
        $this->adjustLocationId = InventoryLocation::where('seller_id', auth()->id())->orderByDesc('is_default')->value('id');
        $this->resetErrorBag();
    }

    public function applyAdjust(InventoryService $inventory): void
    {
        $this->validate([
            'adjustMode' => 'required|in:change,set',
            'adjustDelta' => $this->adjustMode === 'change' ? 'required|integer|not_in:0|between:-100000,100000' : 'nullable',
            'adjustTarget' => $this->adjustMode === 'set' ? 'required|integer|min:0|max:1000000' : 'nullable',
            'adjustReason' => 'required|in:'.implode(',', array_diff(StockMovement::REASONS, ['sale'])),
            'adjustNote' => 'nullable|string|max:200',
        ], ['adjustDelta.not_in' => 'Enter how many to add (or a negative number to remove).']);

        $product = $this->ownedProduct($this->adjustProductId);
        $variant = $this->adjustVariantId ? $this->ownedVariant($product, $this->adjustVariantId) : null;

        $delta = $this->adjustMode === 'set'
            ? (int) $this->adjustTarget - $this->currentLevel($product, $variant, $this->adjustLocationId)
            : $this->adjustDelta;

        if ($delta === 0) {
            $this->adjustProductId = null;
            $this->dispatch('toast', message: 'Count matches — nothing to change.');

            return;
        }

        $inventory->adjust($product, $delta, $this->adjustMode === 'set' ? 'correction' : $this->adjustReason, [
            'variant_id' => $variant?->id,
            'location_id' => $this->adjustLocationId,
            'note' => $this->adjustNote ?: ($this->adjustMode === 'set' ? 'Stock count' : null),
        ]);

        $this->adjustProductId = null;
        $this->dispatch('toast', message: 'Stock updated.');
    }

    public function restockToReorderQty(int $productId, InventoryService $inventory): void
    {
        $product = $this->ownedProduct($productId);
        if ($product->hasVariants()) {
            return; // per-option listings are restocked option by option
        }
        $target = (int) ($product->reorder_qty ?: ($product->reorder_point + 10));
        $delta = $target - (int) $product->quantity;
        if ($delta > 0) {
            $inventory->adjust($product, $delta, 'restock', ['note' => 'Quick restock to target']);
            $this->dispatch('toast', message: "Restocked {$product->title} to {$target}.");
        }
    }

    // ---------------- transfer between locations ----------------

    public function openTransfer(int $productId, ?int $variantId = null): void
    {
        $product = $this->ownedProduct($productId);
        $locations = InventoryLocation::where('seller_id', auth()->id())->orderByDesc('is_default')->pluck('id');

        $this->transferProductId = $product->id;
        $this->transferVariantId = $variantId ? $this->ownedVariant($product, $variantId)->id : null;
        $this->transferFrom = $locations[0] ?? null;
        $this->transferTo = $locations[1] ?? null;
        $this->transferQty = 1;
        $this->resetErrorBag();
    }

    public function applyTransfer(InventoryService $inventory): void
    {
        $owned = InventoryLocation::where('seller_id', auth()->id())->pluck('id')->all();
        $this->validate([
            'transferFrom' => ['required', 'integer', 'in:'.implode(',', $owned ?: [0])],
            'transferTo' => ['required', 'integer', 'different:transferFrom', 'in:'.implode(',', $owned ?: [0])],
            'transferQty' => 'required|integer|min:1|max:100000',
        ], ['transferTo.different' => 'Choose a different location to move stock to.']);

        $product = $this->ownedProduct($this->transferProductId);
        $variant = $this->transferVariantId ? $this->ownedVariant($product, $this->transferVariantId) : null;

        $available = $this->currentLevel($product, $variant, $this->transferFrom);
        if ($this->transferQty > $available) {
            $this->addError('transferQty', "Only {$available} on hand at that location.");

            return;
        }

        $from = InventoryLocation::find($this->transferFrom);
        $to = InventoryLocation::find($this->transferTo);
        DB::transaction(function () use ($inventory, $product, $variant, $from, $to) {
            $opts = ['variant_id' => $variant?->id, 'reference' => 'TRANSFER'];
            $inventory->adjust($product, -$this->transferQty, 'transfer', $opts + ['location_id' => $from->id, 'note' => "To {$to->name}"]);
            $inventory->adjust($product, $this->transferQty, 'transfer', $opts + ['location_id' => $to->id, 'note' => "From {$from->name}"]);
        });

        $this->transferProductId = null;
        $this->dispatch('toast', message: "Moved {$this->transferQty} from {$from->name} to {$to->name}.");
    }

    // ---------------- exports ----------------

    public function exportMovements()
    {
        $rows = $this->movementQuery()->with('product:id,title,sku', 'variant:id,label', 'location:id,name')->limit(20000)->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Listing', 'SKU', 'Option', 'Location', 'Reason', 'Change', 'Balance after', 'Reference', 'Note', 'By']);
            foreach ($rows as $m) {
                fputcsv($out, [
                    $m->created_at->format('Y-m-d H:i'), $m->product?->title, $m->product?->sku, $m->variant?->label,
                    $m->location?->name, $m->reason, $m->delta, $m->balance_after, $m->reference, $m->note, $m->actor_name ?? 'System',
                ]);
            }
            fclose($out);
        }, 'stock-movements-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    // ---------------- helpers ----------------

    private function ownedProduct(int $id): Product
    {
        return Product::where('seller_id', auth()->id())->findOrFail($id);
    }

    private function ownedVariant(Product $product, int $id): ProductVariant
    {
        return ProductVariant::where('product_id', $product->id)->findOrFail($id);
    }

    /** On-hand count for a listing/option, at one location when stock is tracked by location. */
    private function currentLevel(Product $product, ?ProductVariant $variant, ?int $locationId): int
    {
        $tracked = ProductStock::where('product_id', $product->id)->exists();
        if ($tracked && $locationId) {
            return (int) ProductStock::where('product_id', $product->id)
                ->where('inventory_location_id', $locationId)
                ->where('product_variant_id', $variant?->id)
                ->value('quantity');
        }

        return $variant ? (int) $variant->quantity : (int) $product->quantity;
    }

    private function movementQuery()
    {
        $sellerId = auth()->id();

        return StockMovement::whereHas('product', fn ($qq) => $qq->where('seller_id', $sellerId)
            ->when($this->q !== '', fn ($w) => $w->where(fn ($s) => $s->where('title', 'like', "%{$this->q}%")->orWhere('sku', 'like', "%{$this->q}%"))))
            ->latest()->orderByDesc('id');
    }

    public function render()
    {
        $sellerId = auth()->id();
        $mine = fn () => Product::where('seller_id', $sellerId)->where('status', '!=', 'archived');

        $products = $mine()
            ->with(['activeVariants' => fn ($v) => $v->orderBy('sort_order')])
            ->when($this->q, fn ($qq) => $qq->where(fn ($w) => $w->where('title', 'like', "%{$this->q}%")->orWhere('sku', 'like', "%{$this->q}%")))
            ->when($this->tab === 'low', fn ($qq) => $qq->whereNotNull('reorder_point')->where('quantity', '>', 0)->whereColumn('quantity', '<=', 'reorder_point'))
            ->when($this->tab === 'out', fn ($qq) => $qq->where('status', 'active')->where('quantity', '<=', 0))
            ->orderBy('title')
            ->paginate(25);

        $locations = InventoryLocation::where('seller_id', $sellerId)->orderByDesc('is_default')->get();
        $byLocation = ProductStock::whereIn('inventory_location_id', $locations->pluck('id'))
            ->selectRaw('inventory_location_id, sum(quantity) as units')->groupBy('inventory_location_id')->pluck('units', 'inventory_location_id');

        return view('livewire.seller.inventory', [
            'products' => $products,
            'locations' => $locations,
            'unitsByLocation' => $byLocation,
            'lowCount' => $mine()->whereNotNull('reorder_point')->where('quantity', '>', 0)->whereColumn('quantity', '<=', 'reorder_point')->count(),
            'outCount' => $mine()->where('status', 'active')->where('quantity', '<=', 0)->count(),
            'totals' => [
                'units' => (int) $mine()->sum('quantity'),
                'value' => (float) $mine()->whereNotNull('cost_price')->sum(DB::raw('quantity * cost_price')),
                'uncosted' => $mine()->whereNull('cost_price')->where('quantity', '>', 0)->count(),
            ],
            'movements' => $this->tab === 'history'
                ? $this->movementQuery()->with('product:id,title', 'variant:id,label', 'location:id,name')->paginate(30, pageName: 'movesPage')
                : collect(),
            'adjustListing' => $this->adjustProductId ? $this->ownedProduct($this->adjustProductId) : null,
            'adjustVariant' => $this->adjustVariantId ? ProductVariant::find($this->adjustVariantId) : null,
            'transferListing' => $this->transferProductId ? $this->ownedProduct($this->transferProductId) : null,
        ]);
    }
}
