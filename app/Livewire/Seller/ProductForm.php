<?php

namespace App\Livewire\Seller;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\AI\ListingAssistantService;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.seller')]
class ProductForm extends Component
{
    use WithFileUploads;

    public ?Product $product = null;

    #[Validate('required|string|max:255')]
    public string $title = '';

    #[Validate('required|exists:categories,id')]
    public ?int $category_id = null;

    public ?int $brand_id = null;

    #[Validate('nullable|string|max:100')]
    public string $part_number = '';

    #[Validate('nullable|string|max:100')]
    public string $oem_number = '';

    #[Validate('nullable|string|max:80')]
    public string $sku = '';

    #[Validate('required|in:new,used,refurbished')]
    public string $condition = 'new';

    #[Validate('required|numeric|min:0.01')]
    public float $price = 0;

    #[Validate('nullable|numeric|lt:price')]
    public ?float $sale_price = null;

    #[Validate('nullable|date')]
    public ?string $sale_starts_at = null;

    #[Validate('nullable|date|after:sale_starts_at')]
    public ?string $deal_ends_at = null;

    public bool $accepts_offers = false;

    #[Validate('nullable|numeric|min:0.01')]
    public ?float $min_offer_price = null;

    // Must sit below the price (and above any auto-decline floor) or every offer would be "accepted".
    #[Validate('nullable|numeric|min:0.01|lt:price')]
    public ?float $auto_accept_offer_price = null;

    public bool $subscription_enabled = true;

    #[Validate('required|numeric|min:5|max:15')]
    public float $subscription_discount_pct = 5;

    #[Validate('required|integer|min:0')]
    public int $quantity = 0;

    #[Validate('nullable|integer|min:0|max:100000')]
    public ?int $reorder_point = null;

    #[Validate('nullable|integer|min:0|max:100000')]
    public ?int $reorder_qty = null;

    #[Validate('nullable|numeric|min:0|max:2000')]
    public ?float $weight_lbs = null;

    #[Validate('nullable|integer|exists:shipping_policies,id')]
    public ?int $shipping_policy_id = null;

    #[Validate('nullable|integer|min:0|max:30')]
    public ?int $handling_days = null;

    public string $description = '';

    /** One bullet per line. */
    public string $bulletText = '';

    #[Validate('nullable|string|max:60')]
    public string $meta_title = '';

    #[Validate('nullable|string|max:160')]
    public string $meta_description = '';

    public array $newImages = [];

    public array $fitmentVehicleIds = [];

    /** @var array<int, array{id:?int,label:string,sku:string,price:?float,quantity:int}> */
    public array $variants = [];

    /** @var array<int, array{id:?int,min_quantity:int,discount_type:string,discount_value:?float}> */
    public array $volumeTiers = [];

    // Auto-repricing rule
    public bool $reprice_enabled = false;
    public string $reprice_strategy = 'beat_lowest';
    public ?float $reprice_amount = 0.50;
    public ?float $reprice_floor = null;
    public ?float $reprice_ceiling = null;

    public function mount(?Product $product = null): void
    {
        if ($product?->exists) {
            abort_unless($product->seller_id === auth()->id(), 403);

            $this->product = $product;
            $this->title = $product->title;
            $this->category_id = $product->category_id;
            $this->brand_id = $product->brand_id;
            $this->part_number = $product->part_number ?? '';
            $this->oem_number = $product->oem_number ?? '';
            $this->sku = $product->sku ?? '';
            $this->condition = $product->condition ?? 'new';
            $this->price = (float) $product->price;
            $this->sale_price = $product->sale_price !== null ? (float) $product->sale_price : null;
            $this->sale_starts_at = $product->sale_starts_at?->format('Y-m-d\TH:i');
            $this->deal_ends_at = $product->deal_ends_at?->format('Y-m-d\TH:i');
            $this->accepts_offers = (bool) $product->accepts_offers;
            $this->min_offer_price = $product->min_offer_price !== null ? (float) $product->min_offer_price : null;
            $this->auto_accept_offer_price = $product->auto_accept_offer_price !== null ? (float) $product->auto_accept_offer_price : null;
            // Fall back to the column defaults: a model created in-process hasn't been
            // refreshed, so DB defaults aren't on it yet.
            $this->subscription_enabled = (bool) ($product->subscription_enabled ?? true);
            $this->subscription_discount_pct = (float) ($product->subscription_discount_pct ?? 5);
            $this->quantity = $product->quantity;
            $this->reorder_point = $product->reorder_point;
            $this->reorder_qty = $product->reorder_qty;
            $this->weight_lbs = $product->weight_lbs !== null ? (float) $product->weight_lbs : null;
            $this->shipping_policy_id = $product->shipping_policy_id;
            $this->handling_days = $product->handling_days;
            $this->description = $product->description ?? '';
            $this->bulletText = collect($product->bullet_points ?? [])->implode("\n");
            $this->meta_title = $product->meta_title ?? '';
            $this->meta_description = $product->meta_description ?? '';
            $this->fitmentVehicleIds = $product->fitments->pluck('id')->all();
            $this->variants = $product->variants->map(fn ($v) => [
                'id' => $v->id,
                'label' => $v->label,
                'sku' => $v->sku ?? '',
                'price' => $v->price !== null ? (float) $v->price : null,
                'quantity' => $v->quantity,
                'media_id' => $v->media_id,
            ])->all();

            $this->volumeTiers = $product->volumeDiscounts->map(fn ($t) => [
                'id' => $t->id,
                'min_quantity' => $t->min_quantity,
                'discount_type' => $t->discount_type,
                'discount_value' => (float) $t->discount_value,
            ])->all();

            if ($rule = $product->repricingRule) {
                $this->reprice_enabled = (bool) $rule->is_active;
                $this->reprice_strategy = $rule->strategy;
                $this->reprice_amount = (float) $rule->amount;
                $this->reprice_floor = (float) $rule->floor_price;
                $this->reprice_ceiling = $rule->ceiling_price !== null ? (float) $rule->ceiling_price : null;
            }
        } elseif ($tid = request()->integer('template')) {
            $tpl = \App\Models\ProductTemplate::where('seller_id', auth()->id())->find($tid);
            $p = $tpl?->payload ?? [];
            $this->category_id = $p['category_id'] ?? null;
            $this->brand_id = $p['brand_id'] ?? null;
            $this->condition = $p['condition'] ?? 'new';
            $this->price = (float) ($p['price'] ?? 0);
            $this->weight_lbs = $p['weight_lbs'] ?? null;
            $this->description = $p['description'] ?? '';
            $this->bulletText = collect($p['bullet_points'] ?? [])->implode("\n");
            $this->fitmentVehicleIds = $p['fitment_ids'] ?? [];
        }
    }

    public function addVolumeTier(): void
    {
        $this->volumeTiers[] = ['id' => null, 'min_quantity' => 2, 'discount_type' => 'percentage', 'discount_value' => 5];
    }

    public function removeVolumeTier(int $i): void
    {
        unset($this->volumeTiers[$i]);
        $this->volumeTiers = array_values($this->volumeTiers);
    }

    public function addVariant(): void
    {
        $this->variants[] = ['id' => null, 'label' => '', 'sku' => '', 'price' => null, 'quantity' => 0, 'media_id' => null];
    }

    public function removeVariant(int $index): void
    {
        unset($this->variants[$index]);
        $this->variants = array_values($this->variants);
    }

    /** Assign one of this listing's gallery photos to represent a specific variant (e.g. the blue one for "Blue"). */
    public function assignVariantImage(int $index, ?int $mediaId): void
    {
        if (! isset($this->variants[$index])) {
            return;
        }

        // Only let a seller point a variant at a photo that actually belongs to this listing.
        if ($mediaId !== null && ! ($this->product?->media()->whereKey($mediaId)->exists())) {
            return;
        }

        $this->variants[$index]['media_id'] = $mediaId;
    }

    #[On('fitment-updated')]
    public function setFitments(array $ids): void
    {
        $this->fitmentVehicleIds = array_values(array_unique(array_map('intval', $ids)));
    }

    public string $vinLookup = '';

    public ?string $fitmentSuggestNote = null;

    public function suggestFitmentsFromPart(\App\Services\Seller\FitmentSuggestService $suggest): void
    {
        $ids = $suggest->fromPartNumber($this->part_number ?: null, $this->oem_number ?: null)->all();

        if (! $ids) {
            $this->fitmentSuggestNote = 'No other listings of this part number to learn from yet.';

            return;
        }

        $this->dispatch('fitments-suggested', ids: $ids)->to(\App\Livewire\Seller\FitmentPicker::class);
        $this->fitmentSuggestNote = count($ids).' vehicle(s) added from matching listings — review below.';
    }

    public function suggestFitmentsFromVin(\App\Services\Seller\FitmentSuggestService $suggest): void
    {
        $result = $suggest->fromVin($this->vinLookup);

        if (! $result['ok']) {
            $this->fitmentSuggestNote = "Couldn't read that VIN. Check all 17 characters.";

            return;
        }
        if (! $result['vehicle_ids']) {
            $this->fitmentSuggestNote = "Decoded {$result['label']}, but no matching vehicle is in our catalog.";

            return;
        }

        $this->dispatch('fitments-suggested', ids: $result['vehicle_ids'])->to(\App\Livewire\Seller\FitmentPicker::class);
        $this->fitmentSuggestNote = "Added {$result['label']} fitments — review below.";
        $this->vinLookup = '';
    }

    public function generateWithAi(ListingAssistantService $ai): void
    {
        $this->validate(['part_number' => 'required|string']);

        $result = $ai->generateListingDraft($this->part_number, $this->category_id);

        $this->title = $result['title'] ?? $this->title;
        $this->description = $result['description'] ?? $this->description;
        if (! empty($result['bullets']) && is_array($result['bullets'])) {
            $this->bulletText = implode("\n", $result['bullets']);
        }

        // The description editor lives in a wire:ignore block (so it survives re-renders
        // while the seller is typing) — push the AI draft into it explicitly.
        $this->dispatch('rich-text-set', field: 'description', html: $this->description);
        $this->dispatch('toast', message: 'Draft generated — review before saving.');
    }

    public function removeImage(int $mediaId): void
    {
        abort_unless($this->product, 404);
        $media = $this->product->media()->whereKey($mediaId)->first();
        $media?->delete();
    }

    /** Move a photo to the front of the gallery — it becomes the listing's cover / primary image. */
    public function setCoverImage(int $mediaId): void
    {
        abort_unless($this->product, 404);
        $this->reorderImages(
            collect([$mediaId])
                ->merge($this->product->getMedia('images')->pluck('id')->reject(fn ($id) => $id === $mediaId))
                ->all()
        );
    }

    /** @param list<int> $orderedMediaIds every photo id for this listing, in the seller's chosen display order */
    public function reorderImages(array $orderedMediaIds): void
    {
        abort_unless($this->product, 404);
        $owned = $this->product->getMedia('images')->pluck('id');

        foreach (array_values($orderedMediaIds) as $position => $mediaId) {
            if ($owned->contains($mediaId)) {
                \Spatie\MediaLibrary\MediaCollections\Models\Media::whereKey($mediaId)->update(['order_column' => $position + 1]);
            }
        }

        $this->product->load('media'); // refresh the cached relation so the new order renders immediately.
    }

    public function saveDraft(): void
    {
        $this->persist('draft');
    }

    /** Soft-delete this listing (no undo in the UI). Blocked once it has order history — archive it instead. */
    public function delete()
    {
        abort_unless($this->product && $this->product->seller_id === auth()->id(), 404);

        if (\App\Models\OrderItem::where('product_id', $this->product->id)->exists()) {
            $this->dispatch('toast', message: "Can't delete — this listing has order history. Archive it instead.", kind: 'error');

            return;
        }

        $this->product->delete();

        return redirect()->route('seller.products.index')->with('success', 'Listing deleted.');
    }

    public function submit(): void
    {
        // Approved sellers publish immediately unless the platform requires review.
        $this->persist(config('marketplace.listings.review_required') ? 'pending_review' : 'active');
    }

    private function persist(string $status): void
    {
        if ($status === 'active' && filled($this->product?->moderation_note)) {
            $status = 'pending_review'; // taken down by staff: needs their review to go live again
        }

        $this->validate();

        if ($this->accepts_offers && $this->auto_accept_offer_price !== null && $this->min_offer_price !== null
            && $this->auto_accept_offer_price <= $this->min_offer_price) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'auto_accept_offer_price' => 'Must be higher than your auto-decline price.',
            ]);
        }

        $this->variants = array_values(array_filter($this->variants, fn ($v) => filled($v['label'] ?? null)));
        if ($this->variants) {
            $this->validate([
                'variants.*.label' => 'required|string|max:120',
                'variants.*.sku' => 'nullable|string|max:80',
                'variants.*.price' => 'nullable|numeric|min:0.01',
                'variants.*.quantity' => 'required|integer|min:0',
            ]);
        }

        if ($this->shipping_policy_id && ! \App\Models\ShippingPolicy::where('seller_id', auth()->id())->whereKey($this->shipping_policy_id)->exists()) {
            $this->shipping_policy_id = null;
        }

        $bullets = collect(explode("\n", $this->bulletText))
            ->map(fn ($l) => trim($l))->filter()->values()->all();

        $data = [
            'seller_id' => auth()->id(),
            'title' => $this->title,
            'category_id' => $this->category_id,
            'brand_id' => $this->brand_id ?: null,
            'part_number' => $this->part_number ?: null,
            'oem_number' => $this->oem_number ?: null,
            'sku' => $this->sku ?: null,
            'condition' => $this->condition,
            'price' => $this->price,
            'sale_price' => $this->sale_price,
            'sale_starts_at' => $this->sale_price ? ($this->sale_starts_at ?: null) : null,
            'deal_ends_at' => $this->sale_price ? ($this->deal_ends_at ?: null) : null,
            'accepts_offers' => $this->accepts_offers,
            'min_offer_price' => $this->accepts_offers ? $this->min_offer_price : null,
            'auto_accept_offer_price' => $this->accepts_offers ? $this->auto_accept_offer_price : null,
            'subscription_enabled' => $this->subscription_enabled,
            'subscription_discount_pct' => $this->subscription_discount_pct,
            'quantity' => $this->quantity,
            'reorder_point' => $this->reorder_point,
            'reorder_qty' => $this->reorder_qty,
            'weight_lbs' => $this->weight_lbs,
            'shipping_policy_id' => $this->shipping_policy_id ?: null,
            'handling_days' => $this->handling_days ?: null,
            'description' => app(\App\Support\Html\DescriptionSanitizer::class)->sanitize($this->description),
            'bullet_points' => $bullets ?: null,
            'meta_title' => $this->meta_title ?: null,
            'meta_description' => $this->meta_description ?: null,
            'status' => $status,
            'published_at' => $status === 'active'
                ? ($this->product?->published_at ?? now())
                : $this->product?->published_at,
        ];

        if ($this->product) {
            $before = (int) $this->product->quantity;
            $this->product->update($data);
            $product = $this->product;
            // With options, stock is per option (logged in syncVariants) — not this headline number.
            if (! $this->variants && $before !== (int) $product->quantity) {
                \App\Models\StockMovement::create([
                    'product_id' => $product->id,
                    'delta' => (int) $product->quantity - $before,
                    'reason' => 'correction',
                    'note' => 'Edited on the listing form',
                    'actor_id' => \App\Support\SellerContext::actor()?->id,
                    'actor_name' => \App\Support\SellerContext::actor()?->name,
                    'balance_after' => (int) $product->quantity,
                ]);
            }
        } else {
            $data['slug'] = Str::slug($this->title).'-'.Str::random(6);
            $product = Product::create($data);
            $this->product = $product;
            if (! $this->variants && (int) $product->quantity !== 0) {
                \App\Models\StockMovement::create([
                    'product_id' => $product->id,
                    'delta' => (int) $product->quantity,
                    'reason' => 'restock',
                    'note' => 'Opening stock',
                    'actor_id' => \App\Support\SellerContext::actor()?->id,
                    'actor_name' => \App\Support\SellerContext::actor()?->name,
                    'balance_after' => (int) $product->quantity,
                ]);
            }
        }

        $product->fitments()->sync($this->fitmentVehicleIds);
        $this->syncVariants($product);
        $this->syncVolumeTiers($product);
        $this->syncRepricingRule($product);

        foreach ($this->newImages as $image) {
            $product->addMedia($image->getRealPath())
                ->usingFileName($image->getClientOriginalName())
                ->toMediaCollection('images');
        }
        $this->newImages = [];

        session()->flash('success', $status === 'draft'
            ? 'Saved as a draft.'
            : 'Product saved and submitted for review.');

        $this->redirect(route('seller.products.index'), navigate: false);
    }

    private function syncVariants(Product $product): void
    {
        $keepIds = [];
        $actor = \App\Support\SellerContext::actor();
        $log = function (\App\Models\ProductVariant $variant, int $delta, string $reason, string $note) use ($product, $actor) {
            if ($delta === 0) {
                return;
            }
            \App\Models\StockMovement::create([
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'delta' => $delta,
                'reason' => $reason,
                'note' => $note,
                'actor_id' => $actor?->id,
                'actor_name' => $actor?->name,
                'balance_after' => (int) $variant->quantity,
            ]);
        };

        foreach ($this->variants as $i => $v) {
            $payload = [
                'label' => $v['label'],
                'sku' => $v['sku'] ?: null,
                'price' => $v['price'] !== null && $v['price'] !== '' ? (float) $v['price'] : null,
                'quantity' => (int) $v['quantity'],
                'media_id' => $v['media_id'] ?? null,
                'sort_order' => $i,
                'is_active' => true,
            ];

            // Saved one model at a time (not a bulk query) so the product's stock total re-syncs.
            $existing = ! empty($v['id']) ? $product->variants()->find($v['id']) : null;
            if ($existing) {
                // Keep the option's real structure (e.g. {"Color":"White"}) unless it was renamed.
                if ($existing->label !== $v['label']) {
                    $payload['options'] = ['Option' => $v['label']];
                }
                $before = (int) $existing->quantity;
                $existing->update($payload);
                $log($existing, (int) $existing->quantity - $before, 'correction', 'Edited on the listing form');
                $keepIds[] = $existing->id;
            } else {
                $created = $product->variants()->create($payload + ['options' => ['Option' => $v['label']]]);
                $log($created, (int) $created->quantity, 'restock', 'Opening stock');
                $keepIds[] = $created->id;
            }
        }

        $product->variants()->whereKeyNot($keepIds)->get()->each->delete();
        $product->refresh()->syncStockFromVariants();
    }

    private function syncVolumeTiers(Product $product): void
    {
        $product->volumeDiscounts()->delete();
        foreach ($this->volumeTiers as $t) {
            if (($t['min_quantity'] ?? 0) >= 2 && ($t['discount_value'] ?? 0) > 0) {
                $product->volumeDiscounts()->create([
                    'min_quantity' => (int) $t['min_quantity'],
                    'discount_type' => in_array($t['discount_type'] ?? '', ['percentage', 'fixed_per_unit'], true) ? $t['discount_type'] : 'percentage',
                    'discount_value' => (float) $t['discount_value'],
                ]);
            }
        }
    }

    private function syncRepricingRule(Product $product): void
    {
        if (! $this->reprice_enabled) {
            $product->repricingRule()?->delete();

            return;
        }

        $product->repricingRule()->updateOrCreate([], [
            'seller_id' => auth()->id(),
            'strategy' => in_array($this->reprice_strategy, ['match_lowest', 'beat_lowest', 'fixed_above_lowest'], true) ? $this->reprice_strategy : 'beat_lowest',
            'amount' => max(0, (float) ($this->reprice_amount ?? 0)),
            'floor_price' => (float) ($this->reprice_floor ?? $product->price),
            'ceiling_price' => $this->reprice_ceiling !== null && $this->reprice_ceiling !== '' ? (float) $this->reprice_ceiling : null,
            'is_active' => true,
        ]);
    }

    public function runRepriceNow(\App\Services\Seller\RepricingService $repricer): void
    {
        abort_unless($this->product?->exists && $this->product->repricingRule, 422);
        $result = $repricer->apply($this->product->repricingRule);
        $this->price = (float) $this->product->fresh()->price;
        $this->dispatch('toast', message: $result);
    }

    public function render()
    {
        $quality = null;
        $priceIntel = null;
        if ($this->product?->exists) {
            $quality = $this->product->qualityScore();
            $priceIntel = app(\App\Services\Seller\PriceIntelligenceService::class)->forProduct($this->product);
        }

        return view('livewire.seller.product-form', [
            'categories' => Category::where('is_active', true)->orWhere('id', $this->product?->category_id)->orderBy('name')->get(),
            'brands' => Brand::orderBy('name')->get(),
            'existingImages' => $this->product?->getMedia('images') ?? collect(),
            'quality' => $quality,
            'priceIntel' => $priceIntel,
            'shippingPolicies' => \App\Models\ShippingPolicy::where('seller_id', auth()->id())
                ->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'handling_days', 'is_default']),
        ]);
    }
}
