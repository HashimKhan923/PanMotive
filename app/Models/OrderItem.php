<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'seller_id', 'product_id', 'product_variant_id', 'product_title',
        'variant_label', 'product_sku',
        'quantity', 'unit_price', 'discount_amount', 'line_total', 'tax_amount', 'commission_rate',
        'platform_fee_amount', 'offsite_ad_fee', 'affiliate_fee', 'label_fee', 'seller_payout_amount', 'cashback_amount', 'status',
        'carrier', 'tracking_number', 'shipped_at', 'delivered_at',
        'payout_released_at', 'cashback_credited_at',
        'stock_committed_at', 'cancelled_at', 'cancel_reason', 'cancel_note',
        'fit_vehicle_id', 'fit_vehicle_label', 'fit_confirmed',
    ];

    protected $casts = [
        'status' => OrderStatus::class,
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'payout_released_at' => 'datetime',
        'cashback_credited_at' => 'datetime',
        'stock_committed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'cashback_amount' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'commission_rate' => 'decimal:2',
        'platform_fee_amount' => 'decimal:2',
        'offsite_ad_fee' => 'decimal:2',
        'affiliate_fee' => 'decimal:2',
        'label_fee' => 'decimal:2',
        'seller_payout_amount' => 'decimal:2',
        'fit_confirmed' => 'boolean',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** The photo for this line: the chosen variant's own photo, else the product's first photo. Null if neither exists. */
    public function imageUrl(): ?string
    {
        $media = $this->variant?->media ?? $this->product?->getFirstMedia('images');

        return $media ? ($media->getUrl('thumb') ?: $media->getUrl()) : null;
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    public function returnRequests(): HasMany
    {
        return $this->hasMany(ReturnRequest::class)->latest();
    }

    /**
     * Ship-by date: the listing's handling time (business days) counted from when the
     * order was paid. Late shipments hurt the seller's on-time rate, so this is surfaced.
     */
    public function shipBy(): ?\Illuminate\Support\Carbon
    {
        if (! in_array($this->status, [OrderStatus::Paid, OrderStatus::SellerAccepted], true)) {
            return null;
        }

        return $this->handlingDeadline();
    }

    /** The promised ship date regardless of status — used to judge on-time shipping after the fact. */
    public function handlingDeadline(): ?\Illuminate\Support\Carbon
    {
        $paidAt = $this->order?->placed_at ?? $this->created_at;
        $days = $this->product?->effectiveHandlingDays() ?? 2;

        return $paidAt?->copy()->addWeekdays(max(0, $days))->endOfDay();
    }

    public function isLate(): bool
    {
        return (bool) $this->shipBy()?->isPast();
    }

    /** Public carrier tracking page for this shipment, when the carrier is one we know. */
    public function trackingUrl(): ?string
    {
        if (! $this->tracking_number) {
            return null;
        }
        $n = urlencode(preg_replace('/\s+/', '', $this->tracking_number));

        return match (strtoupper((string) $this->carrier)) {
            'USPS' => "https://tools.usps.com/go/TrackConfirmAction?tLabels={$n}",
            'UPS' => "https://www.ups.com/track?tracknum={$n}",
            'FEDEX' => "https://www.fedex.com/fedextrack/?trknbr={$n}",
            'DHL' => "https://www.dhl.com/us-en/home/tracking/tracking-express.html?tracking-id={$n}",
            default => null,
        };
    }

    /** Last day a return can be opened (delivery + return window), or null if not yet delivered. */
    public function returnDeadline(): ?\Illuminate\Support\Carbon
    {
        $delivered = $this->delivered_at ?? $this->shipped_at;

        $platform = (int) config('marketplace.returns.window_days', 30);
        $store = (int) ($this->seller?->sellerProfile?->return_window_days ?? $platform);

        return $delivered?->copy()->addDays(max($platform, $store))->endOfDay();
    }

    public function isWithinReturnWindow(): bool
    {
        $deadline = $this->returnDeadline();

        return $deadline === null || $deadline->isFuture();
    }

    public function activeReturn(): ?ReturnRequest
    {
        return $this->returnRequests()
            ->whereIn('status', ['requested', 'approved', 'received', 'refunded'])
            ->first();
    }

    /** Computed once at creation — this is what Phase 4's payout job reads */
    public static function calculateCommission(float $lineTotal, float $rate): array
    {
        $fee = round($lineTotal * ($rate / 100), 2);

        return [
            'platform_fee_amount' => $fee,
            'seller_payout_amount' => round($lineTotal - $fee, 2),
        ];
    }
}
