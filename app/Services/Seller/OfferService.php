<?php

namespace App\Services\Seller;

use App\Models\Coupon;
use App\Models\Offer;
use App\Models\Product;
use App\Models\User;
use App\Notifications\OfferActivity;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OfferService
{
    public function create(User $buyer, Product $product, float $pricePerUnit, int $qty = 1, ?string $message = null): Offer
    {
        abort_unless($product->accepts_offers && $product->status === 'active', 422, 'This listing is not accepting offers.');
        abort_unless($product->seller_id !== $buyer->id, 403);
        if (\App\Models\SellerBlockedBuyer::isBlocked($product->seller_id, $buyer->id)) {
            throw ValidationException::withMessages(['offer_price' => "This seller isn't accepting offers from your account."]);
        }

        $pricePerUnit = round($pricePerUnit, 2);
        $qty = max(1, min(99, $qty));
        $list = $product->effectivePrice();

        if ($pricePerUnit >= $list) {
            throw ValidationException::withMessages(['offer_price' => 'Offer below the listed price, or just buy it now.']);
        }
        if ($product->min_offer_price && $pricePerUnit < (float) $product->min_offer_price) {
            throw ValidationException::withMessages(['offer_price' => 'The seller only accepts offers of $'.number_format($product->min_offer_price, 2).' or more.']);
        }

        // One open offer per buyer/product.
        Offer::where('product_id', $product->id)->where('buyer_id', $buyer->id)
            ->whereIn('status', ['pending', 'countered'])->update(['status' => 'withdrawn']);

        $offer = Offer::create([
            'product_id' => $product->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $product->seller_id,
            'quantity' => $qty,
            'offer_price' => $pricePerUnit,
            'message' => $message,
            'status' => 'pending',
            'expires_at' => now()->addHours(48),
        ]);

        // Seller's auto-accept price: a good-enough offer is a sale right now, no waiting.
        if ($product->auto_accept_offer_price && $pricePerUnit >= (float) $product->auto_accept_offer_price) {
            $this->accept($offer, 'seller');
            $product->seller?->notify(new OfferActivity($offer->fresh(), 'auto_accepted', route('seller.offers.index')));

            return $offer->fresh();
        }

        $product->seller?->notify(new OfferActivity($offer, 'received', route('seller.offers.index')));

        return $offer;
    }

    /** Buyer turns down the seller's counter. */
    public function declineCounter(Offer $offer): void
    {
        abort_unless($offer->status === 'countered', 422, 'There\'s no counter-offer to decline.');
        $offer->update(['status' => 'declined', 'responded_at' => now()]);
        $offer->seller?->notify(new OfferActivity($offer, 'counter_declined', route('seller.offers.index')));
    }

    public function counter(Offer $offer, float $counterPerUnit): void
    {
        abort_unless($offer->isActionable(), 422, 'This offer can no longer be countered.');

        $counterPerUnit = round($counterPerUnit, 2);
        $list = $offer->product->effectivePrice();
        if ($counterPerUnit <= $offer->offer_price || $counterPerUnit >= $list) {
            throw ValidationException::withMessages(['counter_price' => 'Counter must be above the offer and below the list price.']);
        }

        $offer->update([
            'status' => 'countered',
            'counter_price' => $counterPerUnit,
            'expires_at' => now()->addHours(48),
            'responded_at' => now(),
        ]);

        $offer->buyer?->notify(new OfferActivity($offer, 'countered', route('products.show', $offer->product->slug)));
    }

    public function decline(Offer $offer): void
    {
        abort_if(in_array($offer->status, ['accepted', 'declined'], true), 422);
        $offer->update(['status' => 'declined', 'responded_at' => now()]);
        $offer->buyer?->notify(new OfferActivity($offer, 'declined', route('products.show', $offer->product->slug)));
    }

    /**
     * Seller accepts the buyer's pending offer, or the buyer accepts the seller's counter.
     * Mints a single-use code locked to that buyer, that listing and that quantity.
     */
    public function accept(Offer $offer, string $by = 'seller'): Offer
    {
        abort_if(in_array($offer->status, ['accepted', 'declined', 'expired', 'withdrawn'], true), 422, 'This offer is closed.');

        // The clock is checked here too — the hourly expiry sweep may not have run yet.
        if ($offer->expires_at && $offer->expires_at->isPast()) {
            $offer->update(['status' => 'expired']);
            abort(422, 'This offer has expired.');
        }

        // Only the other side can accept: the seller accepts a buyer's offer, the buyer a counter.
        abort_unless($by === 'seller' ? $offer->status === 'pending' : $offer->status === 'countered', 422,
            $by === 'seller' ? 'You\'ve countered this offer — it\'s waiting on the buyer.' : 'There\'s no counter-offer to accept.');

        $agreed = $offer->listPrice();               // counter_price ?? offer_price
        $list = $offer->product->effectivePrice();
        $discount = round(max(0, $list - $agreed) * $offer->quantity, 2);

        $code = 'OFFER-'.strtoupper(Str::random(6));
        Coupon::create([
            'code' => $code,
            'type' => 'fixed',
            'value' => $discount,
            'seller_id' => $offer->seller_id,
            'user_id' => $offer->buyer_id,
            'product_id' => $offer->product_id,
            'min_quantity' => $offer->quantity,
            'usage_limit' => 1,
            'usage_limit_per_user' => 1,
            'expires_at' => now()->addHours(48),
            'is_active' => true,
        ]);

        $offer->update([
            'status' => 'accepted',
            'coupon_code' => $code,
            'expires_at' => now()->addHours(48),
            'responded_at' => now(),
        ]);

        if ($by === 'buyer') {
            $offer->seller?->notify(new OfferActivity($offer, 'counter_accepted', route('seller.offers.index', ['filter' => 'accepted'])));
        } else {
            $offer->buyer?->notify(new OfferActivity($offer, 'accepted', route('products.show', $offer->product->slug)));
        }

        return $offer;
    }

    public function expireStale(): int
    {
        return Offer::whereIn('status', ['pending', 'countered'])
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);
    }
}
