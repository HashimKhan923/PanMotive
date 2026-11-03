<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AdminTools;
use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Notifications\AccountNotice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ProductController extends Controller
{
    use AdminTools;

    /** What a staff member can do to a listing, and the status it ends up in. */
    private const DECISIONS = ['approve' => 'active', 'reject' => 'rejected', 'remove' => 'archived'];

    public function index(Request $request)
    {
        $status = $request->query('status', '');
        $counts = Product::selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status');

        $products = Product::with('seller.sellerProfile', 'category.parent', 'brand', 'media')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w
                ->where('title', 'like', $this->like($request->q))
                ->orWhere('sku', 'like', $this->like($request->q))
                ->orWhere('part_number', 'like', $this->like($request->q))
                ->orWhere('oem_number', 'like', $this->like($request->q))))
            ->when($request->filled('category'), fn ($q) => $q->whereIn('category_id',
                Category::where('parent_id', $request->integer('category'))->pluck('id')->push($request->integer('category'))))
            ->when($request->query('quality'), fn ($q, $issue) => match ($issue) {
                'out_of_stock' => $q->where('quantity', '<=', 0),
                'low_stock' => $q->where('quantity', '>', 0)->whereNotNull('reorder_point')->whereColumn('quantity', '<=', 'reorder_point'),
                'no_fitment' => $q->whereDoesntHave('fitments'),
                'no_photo' => $q->whereDoesntHave('media'),
                'no_brand' => $q->whereNull('brand_id'),
                'uncategorised' => $q->whereHas('category', fn ($c) => $c->where('slug', 'other-parts')),
                default => $q,
            })
            ->when($request->filled('stock'), fn ($q) => $request->stock === 'out' ? $q->where('quantity', '<=', 0) : $q->where('quantity', '>', 0))
            ->latest()->paginate(25)->withQueryString();

        return view('admin.products.index', [
            'products' => $products, 'status' => $status,
            'categories' => Category::whereNull('parent_id')->orderBy('name')->get(['id', 'name']),
            'tabs' => [
                '' => ['All', $counts->sum()],
                'pending_review' => ['Pending review', $counts['pending_review'] ?? 0],
                'active' => ['Active', $counts['active'] ?? 0],
                'draft' => ['Draft', $counts['draft'] ?? 0],
                'rejected' => ['Rejected', $counts['rejected'] ?? 0],
                'archived' => ['Removed', $counts['archived'] ?? 0],
            ],
        ]);
    }

    public function show(Product $product)
    {
        $product->load('seller.sellerProfile', 'category.parent', 'brand', 'media', 'fitments.make', 'fitments.model');

        return view('admin.products.show', [
            'product' => $product,
            'categories' => Category::with('parent')->orderBy('name')->get(['id', 'name', 'parent_id']),
            'brands' => Brand::orderBy('name')->get(['id', 'name']),
            'sold' => (int) \App\Models\OrderItem::where('product_id', $product->id)->whereNotIn('status', ['placed', 'cancelled', 'refunded'])->sum('quantity'),
            'history' => \Spatie\Activitylog\Models\Activity::where('subject_type', $product->getMorphClass())->where('subject_id', $product->id)->with('causer')->latest()->take(8)->get(),
        ]);
    }

    /** Fix how a listing is filed. Seller-owned details like price stay the seller's. */
    public function update(Request $request, Product $product)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
        ]);

        $before = $product->only(array_keys($data));
        $product->update($data);
        Cache::forget('shop.category_roots');
        $this->audit('Edited listing', $product, ['before' => $before, 'after' => $data]);

        return back()->with('success', 'Listing updated.');
    }

    public function moderate(Request $request, Product $product)
    {
        $data = $this->validateDecision($request);

        $this->decide($product, $data['decision'], $data['reason'] ?? null);

        return back()->with('success', 'Listing '.$this->pastTense($data['decision']).'.');
    }

    public function bulk(Request $request)
    {
        $data = $this->validateDecision($request) + $request->validate(['ids' => 'required|array|max:100', 'ids.*' => 'integer']);

        $products = Product::with('seller')->whereIn('id', $data['ids'])->get();
        $products->each(fn ($p) => $this->decide($p, $data['decision'], $data['reason'] ?? null));

        return back()->with('success', $products->count().' listings '.$this->pastTense($data['decision']).'.');
    }

    private function validateDecision(Request $request): array
    {
        return $request->validate([
            'decision' => 'required|in:approve,reject,remove',
            'reason' => 'required_unless:decision,approve|nullable|string|max:255',
        ]);
    }

    private function decide(Product $product, string $decision, ?string $reason): void
    {
        $wasWaiting = $product->status === 'pending_review';

        $product->forceFill([
            'status' => self::DECISIONS[$decision],
            'moderation_note' => $reason,
            'published_at' => $decision === 'approve' ? ($product->published_at ?? now()) : $product->published_at,
        ])->save();

        Cache::forget('shop.category_roots');
        $this->audit('Listing '.$this->pastTense($decision), $product, ['reason' => $reason]);

        if ($decision === 'approve' && $wasWaiting) {
            $product->seller?->notify(new AccountNotice('Your listing is live', "\"{$product->title}\" was approved and shoppers can buy it now."));
        }

        if ($decision !== 'approve') {
            $product->seller?->notify(new AccountNotice(
                'A listing was taken down', "\"{$product->title}\" is no longer visible to shoppers. Reason: {$reason}"
            ));
        }
    }

    private function pastTense(string $decision): string
    {
        return ['approve' => 'approved', 'reject' => 'rejected', 'remove' => 'removed'][$decision];
    }
}
