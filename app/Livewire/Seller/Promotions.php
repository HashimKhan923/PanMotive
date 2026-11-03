<?php

namespace App\Livewire\Seller;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\SaleEvent;
use App\Services\Marketing\SaleEventService;
use App\Support\SellerContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Coupon codes, store-wide sales (strike-through markdowns) and the private one-person codes
 * created by accepted offers and customer thank-yous.
 */
#[Layout('layouts.seller')]
class Promotions extends Component
{
    #[Url]
    public string $tab = 'sales'; // sales | codes | private

    // Sale form
    public bool $showSaleForm = false;
    public string $saleName = '';
    public ?float $salePercent = 15;
    public ?string $saleStarts = null;
    public ?string $saleEnds = null;
    /** @var array<int, string> */
    public array $saleProducts = [];
    public string $saleSearch = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    // form fields
    public string $code = '';
    public string $type = 'percentage';
    public ?float $value = null;
    public ?float $min_order_total = null;
    public ?float $max_discount_amount = null;
    public ?int $usage_limit = null;
    public ?int $usage_limit_per_user = 1;
    public ?string $starts_at = null;
    public ?string $expires_at = null;
    public bool $is_active = true;

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32', 'alpha_dash',
                Rule::unique('coupons', 'code')->ignore($this->editingId)],
            'type' => ['required', 'in:percentage,fixed'],
            'value' => ['required', 'numeric', 'min:0.01', $this->type === 'percentage' ? 'max:90' : 'max:100000'],
            'min_order_total' => ['nullable', 'numeric', 'min:0'],
            'max_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'usage_limit_per_user' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ];
    }

    public function newPromotion(): void
    {
        $this->reset(['editingId', 'code', 'type', 'value', 'min_order_total', 'max_discount_amount', 'usage_limit', 'starts_at', 'expires_at']);
        $this->type = 'percentage';
        $this->usage_limit_per_user = 1;
        $this->is_active = true;
        $this->code = strtoupper(Str::random(8));
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $c = $this->owned($id);
        $this->editingId = $c->id;
        $this->code = $c->code;
        $this->type = $c->type;
        $this->value = (float) $c->value;
        $this->min_order_total = $c->min_order_total !== null ? (float) $c->min_order_total : null;
        $this->max_discount_amount = $c->max_discount_amount !== null ? (float) $c->max_discount_amount : null;
        $this->usage_limit = $c->usage_limit;
        $this->usage_limit_per_user = $c->usage_limit_per_user;
        $this->starts_at = $c->starts_at?->format('Y-m-d\TH:i');
        $this->expires_at = $c->expires_at?->format('Y-m-d\TH:i');
        $this->is_active = $c->is_active;
        $this->showForm = true;
    }

    public function save(): void
    {
        abort_unless(SellerContext::can('marketing'), 403);
        $data = $this->validate();

        $payload = [
            ...$data,
            'code' => strtoupper($data['code']),
            'seller_id' => auth()->id(),
            'is_active' => $this->is_active,
        ];

        if ($this->editingId) {
            $this->owned($this->editingId)->update($payload);
        } else {
            Coupon::create($payload);
        }

        $this->showForm = false;
        $this->dispatch('toast', message: 'Promotion saved');
    }

    public function toggle(int $id): void
    {
        $c = $this->owned($id);
        $c->update(['is_active' => ! $c->is_active]);
    }

    public function delete(int $id): void
    {
        $this->owned($id)->delete();
        $this->dispatch('toast', message: 'Promotion deleted');
    }

    /** Public codes only — private (one-buyer) codes can be paused, never rewritten. */
    private function owned(int $id): Coupon
    {
        return Coupon::where('seller_id', auth()->id())->whereNull('user_id')->findOrFail($id);
    }

    public function togglePrivate(int $id): void
    {
        $c = Coupon::where('seller_id', auth()->id())->whereNotNull('user_id')->findOrFail($id);
        $c->update(['is_active' => ! $c->is_active]);
    }

    // ── Sales ────────────────────────────────────────────────────────────

    public function newSale(): void
    {
        $this->reset('saleName', 'saleProducts', 'saleSearch');
        $this->salePercent = 15;
        $this->saleStarts = now()->format('Y-m-d\TH:i');
        $this->saleEnds = now()->addDays(7)->endOfDay()->format('Y-m-d\TH:i');
        $this->showSaleForm = true;
    }

    public function selectAllProducts(): void
    {
        $this->saleProducts = $this->saleCandidates()->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    public function createSale(SaleEventService $sales): void
    {
        abort_unless(SellerContext::can('marketing'), 403);
        $this->validate([
            'saleName' => 'required|string|max:80',
            'salePercent' => 'required|numeric|min:5|max:80',
            'saleStarts' => 'required|date',
            'saleEnds' => 'required|date|after:saleStarts',
            'saleProducts' => 'required|array|min:1',
        ], ['saleProducts.required' => 'Pick at least one listing.'], ['saleEnds' => 'end date']);

        $starts = Carbon::parse($this->saleStarts);
        $ends = Carbon::parse($this->saleEnds);
        if ($ends->diffInDays($starts, true) > 60) {
            $this->addError('saleEnds', 'Sales can run for up to 60 days.');

            return;
        }

        $result = $sales->create(auth()->user(), trim($this->saleName), (float) $this->salePercent,
            $starts->lt(now()) ? now() : $starts, $ends, array_map('intval', $this->saleProducts));

        $n = $result['event']->items()->count();
        $this->showSaleForm = false;
        $this->dispatch('toast', message: "Sale created on {$n} ".Str::plural('listing', $n)
            .($result['skipped'] ? ' — skipped '.count($result['skipped']).' that already had a sale price.' : '.'));
        $this->dispatch('celebrate');
    }

    public function cancelSale(int $id, SaleEventService $sales): void
    {
        abort_unless(SellerContext::can('marketing'), 403);
        $event = SaleEvent::where('seller_id', auth()->id())->whereIn('status', ['scheduled', 'active'])->findOrFail($id);
        $sales->cancel($event);
        $this->dispatch('toast', message: 'Sale ended — prices are back to normal.');
    }

    private function saleCandidates()
    {
        $term = trim($this->saleSearch);

        return Product::where('seller_id', auth()->id())->where('status', 'active')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('title', 'like', "%{$term}%")->orWhere('sku', 'like', "%{$term}%")))
            ->orderBy('title')->limit(200)->get(['id', 'title', 'price', 'sale_price', 'deal_ends_at', 'sku']);
    }

    public function render(SaleEventService $sales)
    {
        $sellerId = auth()->id();
        $codes = Coupon::where('seller_id', $sellerId)->withCount('usages')->latest()->get();

        // What each code actually did: orders, discount given, and the store's sales on those orders.
        $perf = DB::table('coupon_usages')
            ->join('order_items', function ($j) use ($sellerId) {
                $j->on('order_items.order_id', '=', 'coupon_usages.order_id')->where('order_items.seller_id', $sellerId);
            })
            ->whereIn('coupon_usages.coupon_id', $codes->pluck('id'))
            ->whereNotIn('order_items.status', ['placed', 'cancelled', 'refunded'])
            ->groupBy('coupon_usages.coupon_id')
            ->selectRaw('coupon_usages.coupon_id, COUNT(DISTINCT coupon_usages.order_id) orders, SUM(order_items.discount_amount) discount, SUM(order_items.line_total) sales')
            ->get()->keyBy('coupon_id');

        $events = SaleEvent::where('seller_id', $sellerId)->withCount('items')->latest('starts_at')->limit(30)->get();

        return view('livewire.seller.promotions', [
            'promotions' => $codes->whereNull('user_id')->values(),
            'privateCodes' => $codes->whereNotNull('user_id')->values()->load('user:id,name', 'product:id,title'),
            'perf' => $perf,
            'events' => $events,
            'eventPerf' => $events->mapWithKeys(fn ($e) => [$e->id => $sales->performance($e)]),
            'candidates' => $this->showSaleForm ? $this->saleCandidates() : collect(),
        ]);
    }
}
