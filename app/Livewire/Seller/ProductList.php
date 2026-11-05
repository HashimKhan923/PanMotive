<?php

namespace App\Livewire\Seller;

use App\Models\Product;
use App\Models\ProductTemplate;
use App\Support\SellerContext;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.seller')]
class ProductList extends Component
{
    use WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $stock = '';

    #[Url]
    public string $search = '';

    #[Url]
    public string $sort = 'newest';

    /** @var array<int, int> */
    public array $selected = [];

    public bool $selectPage = false;

    // bulk price editor
    public bool $showBulkPrice = false;
    public string $priceMode = 'pct_down';
    public ?float $priceValue = null;

    // inline row editing
    public ?int $editId = null;
    public ?float $editPrice = null;
    public ?int $editQty = null;

    public function updating($name): void
    {
        if (in_array($name, ['status', 'search', 'stock', 'sort'], true)) {
            $this->resetPage();
            $this->reset('selected', 'selectPage');
        }
    }

    public function updatedSelectPage($value): void
    {
        $this->selected = $value ? $this->pageQuery()->pluck('id')->map(fn ($id) => (string) $id)->all() : [];
    }

    private function owned(int $id): Product
    {
        return Product::where('seller_id', auth()->id())->findOrFail($id);
    }

    public function archive(int $id): void
    {
        abort_unless(SellerContext::can('products.manage'), 403);

        $this->owned($id)->update(['status' => 'archived']);
        $this->dispatch('toast', message: 'Listing archived');
    }

    public function activate(int $id): void
    {
        abort_unless(SellerContext::can('products.manage'), 403);

        $product = $this->owned($id);

        // Re-publishing something already reviewed → straight back to active.
        // A brand-new/rejected listing needs review only when the platform requires it.
        $needsReview = config('marketplace.listings.review_required')
            && $product->status !== 'archived'
            && ! $product->published_at;

        $product->forceFill([
            'status' => $needsReview ? 'pending_review' : 'active',
            'published_at' => $needsReview ? $product->published_at : ($product->published_at ?? now()),
        ])->save();

        $this->dispatch('toast', message: $product->status === 'pending_review' ? 'Submitted for review' : 'Listing is now live');
    }

    /**
     * Delete a listing (Product uses SoftDeletes, so this is a soft delete — the
     * row is retained for order-history integrity but disappears from every normal
     * query, including the seller's own product list; there is no undo in the UI).
     * Blocked once the listing has order history, so past-order records keep a
     * seller-visible listing behind them — archive it instead.
     */
    public function delete(int $id): void
    {
        abort_unless(SellerContext::can('products.manage'), 403);

        $product = $this->owned($id);

        if (\App\Models\OrderItem::where('product_id', $product->id)->exists()) {
            $this->dispatch('toast', message: "Can't delete — this listing has order history. Archive it instead.", kind: 'error');

            return;
        }

        try {
            $product->delete();
        } catch (\Illuminate\Database\QueryException $e) {
            $this->dispatch('toast', message: "Can't delete this listing — it's still referenced elsewhere. Try archiving it instead.", kind: 'error');

            return;
        }

        $this->selected = array_values(array_diff($this->selected, [(string) $id, $id]));
        $this->dispatch('toast', message: 'Listing deleted');
    }

    public function duplicate(int $id): void
    {
        abort_unless(SellerContext::can('products.manage'), 403);

        $original = $this->owned($id);
        $copy = $original->replicate(['slug', 'views_count', 'avg_rating', 'reviews_count', 'published_at']);
        $copy->title = $original->title.' (copy)';
        $copy->slug = Str::slug($original->title).'-'.Str::random(6);
        $copy->status = 'draft';
        $copy->save();
        $copy->fitments()->sync($original->fitments->pluck('id'));

        $this->dispatch('toast', message: 'Duplicated as a draft');
    }

    public function bulk(string $action): void
    {
        abort_unless(SellerContext::can('products.manage'), 403);

        $ids = collect($this->selected)->map(fn ($i) => (int) $i);
        $q = Product::where('seller_id', auth()->id())->whereIn('id', $ids);

        if ($action === 'delete') {
            $sellable = $q->pluck('id');
            $hasOrders = \App\Models\OrderItem::whereIn('product_id', $sellable)->distinct()->pluck('product_id');
            $deletable = $sellable->diff($hasOrders);

            Product::whereKey($deletable)->get()->each->delete();

            $this->reset('selected', 'selectPage');
            $this->dispatch('toast', message: $hasOrders->isEmpty()
                ? "{$deletable->count()} listing(s) deleted"
                : "{$deletable->count()} deleted — {$hasOrders->count()} skipped (order history, archive those instead)");

            return;
        }

        // Bulk updates skip model events, so listings staff took down have to be left out by hand.
        if ($action === 'activate') {
            $q->whereNull('moderation_note');
        }

        match ($action) {
            'archive' => $q->update(['status' => 'archived']),
            'activate' => config('marketplace.listings.review_required')
                ? $q->where(fn ($w) => $w->whereNotNull('published_at')->orWhere('status', 'archived'))->update(['status' => 'active'])
                : $q->update(['status' => 'active', 'published_at' => now()]),
            default => null,
        };

        $this->reset('selected', 'selectPage');
        $this->dispatch('toast', message: 'Bulk update applied');
    }

    public function applyBulkPrice(): void
    {
        abort_unless(SellerContext::can('products.manage'), 403);

        $this->validate(['priceValue' => 'required|numeric|min:0']);
        $v = (float) $this->priceValue;

        Product::where('seller_id', auth()->id())
            ->whereIn('id', collect($this->selected)->map(fn ($i) => (int) $i))
            ->get()
            ->each(function ($p) use ($v) {
                $new = match ($this->priceMode) {
                    'pct_up' => $p->price * (1 + $v / 100),
                    'pct_down' => $p->price * (1 - $v / 100),
                    'amt_up' => $p->price + $v,
                    'amt_down' => $p->price - $v,
                    'set' => $v,
                    default => $p->price,
                };
                $p->update(['price' => round(max(0.01, $new), 2)]);
            });

        $this->reset('selected', 'selectPage', 'showBulkPrice', 'priceValue');
        $this->dispatch('toast', message: 'Prices updated');
    }

    public function startEdit(int $id): void
    {
        abort_unless(SellerContext::can('products.manage'), 403);

        $p = $this->owned($id);
        $this->editId = $id;
        $this->editPrice = (float) $p->price;
        $this->editQty = $p->quantity;
    }

    public function saveEdit(): void
    {
        abort_unless(SellerContext::can('products.manage'), 403);

        $this->validate([
            'editPrice' => 'required|numeric|min:0.01',
            'editQty' => 'required|integer|min:0|max:1000000',
        ]);

        $product = $this->owned($this->editId);
        if ($product->sale_price !== null && (float) $product->sale_price >= (float) $this->editPrice) {
            $this->addError('editPrice', 'Must be above the current sale price ($'.number_format((float) $product->sale_price, 2).').');

            return;
        }
        $product->update(['price' => $this->editPrice]);

        // Stock goes through the inventory service so the change is in the movement log.
        // Listings with options are stocked per option, so their total isn't editable here.
        if (! $product->hasVariants() && (int) $this->editQty !== (int) $product->quantity) {
            app(\App\Services\Seller\InventoryService::class)->adjust(
                $product, (int) $this->editQty - (int) $product->quantity, 'correction', ['note' => 'Quick edit on the product list']
            );
        }

        $this->reset('editId', 'editPrice', 'editQty');
        $this->dispatch('toast', message: 'Listing updated');
    }

    public function saveAsTemplate(int $id): void
    {
        abort_unless(SellerContext::can('products.manage'), 403);

        $p = $this->owned($id);
        ProductTemplate::create([
            'seller_id' => auth()->id(),
            'name' => Str::limit($p->title, 40, ''),
            'payload' => [
                'category_id' => $p->category_id,
                'brand_id' => $p->brand_id,
                'condition' => $p->condition,
                'price' => (float) $p->price,
                'weight_lbs' => $p->weight_lbs !== null ? (float) $p->weight_lbs : null,
                'bullet_points' => $p->bullet_points,
                'description' => $p->description,
                'fitment_ids' => $p->fitments()->pluck('vehicles.id')->all(),
            ],
        ]);
        $this->dispatch('toast', message: 'Saved as a template');
    }

    public function deleteTemplate(int $id): void
    {
        abort_unless(SellerContext::can('products.manage'), 403);

        ProductTemplate::where('seller_id', auth()->id())->whereKey($id)->delete();
    }

    public function exportCsv()
    {
        $rows = Product::where('seller_id', auth()->id())
            ->orderBy('title')
            ->get(['title', 'sku', 'part_number', 'oem_number', 'condition', 'price', 'sale_price', 'quantity', 'status']);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['title', 'sku', 'part_number', 'oem_number', 'condition', 'price', 'sale_price', 'quantity', 'status']);
            foreach ($rows as $r) {
                fputcsv($out, [$r->title, $r->sku, $r->part_number, $r->oem_number, $r->condition, $r->price, $r->sale_price, $r->quantity, $r->status]);
            }
            fclose($out);
        }, 'panmotive-catalog-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    private function baseQuery()
    {
        return Product::query()
            ->where('seller_id', auth()->id())
            ->withCount('orderItems')
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->stock === 'out', fn ($q) => $q->where('quantity', '<=', 0))
            ->when($this->stock === 'low', fn ($q) => $q->whereBetween('quantity', [1, 5]))
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('title', 'like', "%{$this->search}%")
                ->orWhere('part_number', 'like', "%{$this->search}%")
                ->orWhere('sku', 'like', "%{$this->search}%")))
            ->when($this->sort === 'newest', fn ($q) => $q->latest())
            ->when($this->sort === 'price_high', fn ($q) => $q->orderByDesc('price'))
            ->when($this->sort === 'price_low', fn ($q) => $q->orderBy('price'))
            ->when($this->sort === 'stock_low', fn ($q) => $q->orderBy('quantity'))
            ->when($this->sort === 'best_selling', fn ($q) => $q->orderByDesc('order_items_count'));
    }

    private function pageQuery()
    {
        return $this->baseQuery()->limit(15)->get(['id']);
    }

    public function render()
    {
        $counts = Product::where('seller_id', auth()->id())
            ->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');

        $products = $this->baseQuery()->paginate(15);

        return view('livewire.seller.product-list', [
            'products' => $products,
            'counts' => $counts,
            'total' => $counts->sum(),
            'templates' => auth()->user()->productTemplates()->latest()->get(),
            'canManage' => SellerContext::can('products.manage'),
        ]);
    }
}
