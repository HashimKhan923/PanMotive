<?php

namespace App\Services\Buyer;

use App\Enums\OrderStatus;
use App\Jobs\ReleaseSellerPayout;
use App\Models\AdminAlert;
use App\Models\BuyerClaim;
use App\Models\OrderItem;
use App\Models\ReturnRequest;
use App\Models\User;
use App\Notifications\BuyerClaimFiled;
use App\Notifications\SellerReturnRequested;
use App\Services\FitGuarantee;
use App\Services\Integrations\WebhookDispatcher;

/**
 * What a buyer can do with something they ordered — confirm it arrived, start a return, escalate a
 * stuck return, open a buyer-protection claim. One set of rules for the website and the app.
 * Problems are thrown as OrderActionRefused, tied to the field they belong to.
 */
class OrderActions
{
    public const CLAIM_ELIGIBLE = [
        'shipped', 'out_for_delivery', 'delivered', 'payout_released', 'return_requested', 'return_approved',
    ];

    /** The return reasons a buyer picks from for this item (the Fit Guarantee one only when it applies). */
    public static function returnReasons(OrderItem $item): array
    {
        return [
            $item->fit_confirmed ? FitGuarantee::REASON : 'Doesn\'t fit my vehicle',
            FitGuarantee::WRONG_ORDER_REASON,
            'Wrong part received',
            'Arrived damaged or defective',
            'No longer needed',
            'Better price elsewhere',
        ];
    }

    public function confirmDelivery(OrderItem $item, User $buyer): void
    {
        if (! in_array($item->status->value, ['shipped', 'out_for_delivery'], true)) {
            throw new OrderActionRefused('item', 'This item isn\'t on its way, so it can\'t be marked delivered.', 400);
        }

        $item->update(['status' => OrderStatus::Delivered, 'delivered_at' => now()]);
        $item->statusHistory()->create([
            'status' => OrderStatus::Delivered->value,
            'note' => 'Buyer confirmed delivery.',
            'changed_by' => $buyer->id,
        ]);
        app(WebhookDispatcher::class)->orderEvent('order.delivered', [$item->fresh('order')]);

        ReleaseSellerPayout::dispatch($item->id);
    }

    public function requestReturn(OrderItem $item, User $buyer, string $reason, ?string $detail): ReturnRequest
    {
        if (ReturnRequest::where('order_item_id', $item->id)->whereIn('status', ['requested', 'approved', 'received'])->exists()) {
            throw new OrderActionRefused('reason', 'A return is already in progress for this item.');
        }
        if (! in_array($item->status, [OrderStatus::Delivered, OrderStatus::PayoutReleased], true)) {
            throw new OrderActionRefused('reason', 'Returns can only be started after the item is delivered.');
        }
        // Built only when it applies — returnDeadline() is null for items with no delivery date.
        if (! $item->isWithinReturnWindow()) {
            throw new OrderActionRefused('reason', 'The return window for this item closed on '
                .$item->returnDeadline()->format('M j, Y').'. You can still contact the seller or open a claim.');
        }

        // Fit Guarantee: only when the listing covered the buyer's vehicle at checkout.
        $guaranteed = $reason === FitGuarantee::REASON && app(FitGuarantee::class)->applies($item);

        $rma = ReturnRequest::create([
            'order_item_id' => $item->id,
            'user_id' => $buyer->id,
            'reason' => $reason,
            'detail' => $detail,
            'status' => 'requested',
            'fit_guarantee' => $guaranteed,
        ]);

        if ($guaranteed) {
            $rma->log('note', 'system', null, 'Covered by the Panmotive Fit Guarantee: the listing said it fits the buyer’s '
                .$item->fit_vehicle_label.'. The seller pays return shipping and can’t decline it without Panmotive review.');
        }

        $item->update(['status' => OrderStatus::ReturnRequested]);
        $item->statusHistory()->create([
            'status' => OrderStatus::ReturnRequested->value,
            'note' => "RMA {$rma->rma_number}: {$reason}",
            'changed_by' => $buyer->id,
        ]);

        $item->seller?->notify(new SellerReturnRequested($rma->setRelation('orderItem', $item)));
        app(WebhookDispatcher::class)->orderEvent('return.requested', [$item->fresh('order')], [
            'rma_number' => $rma->rma_number, 'reason' => $rma->reason,
        ]);

        return $rma;
    }

    public function escalateReturn(ReturnRequest $rma, User $buyer, ?string $note): void
    {
        if (! in_array($rma->status, ['requested', 'approved'], true)) {
            throw new OrderActionRefused('note', 'This return can no longer be escalated.');
        }

        $rma->update(['escalated_at' => now(), 'escalated_by' => 'buyer']);
        $rma->log('escalated', 'buyer', $buyer->id, $note ?: 'Buyer escalated to Panmotive support');

        AdminAlert::create([
            'type' => 'return_escalated',
            'message' => "Return {$rma->rma_number} escalated by the buyer.",
            'related_order_item_id' => $rma->order_item_id,
        ]);
    }

    public function fileClaim(OrderItem $item, User $buyer, string $reason, string $detail): BuyerClaim
    {
        if (! array_key_exists($reason, BuyerClaim::REASONS)) {
            throw new OrderActionRefused('reason', 'Choose a reason for the claim.');
        }
        if (! in_array($item->status->value, self::CLAIM_ELIGIBLE, true)) {
            throw new OrderActionRefused('reason', 'This item is not eligible for a claim right now.');
        }
        if (BuyerClaim::where('order_item_id', $item->id)->whereIn('status', ['open', 'seller_responded', 'escalated'])->exists()) {
            throw new OrderActionRefused('reason', 'You already have an open claim for this item.');
        }

        $claim = BuyerClaim::create([
            'order_item_id' => $item->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $item->seller_id,
            'reason' => $reason,
            'detail' => $detail,
            'amount' => $item->line_total,
            'status' => 'open',
        ]);

        $item->seller?->notify(new BuyerClaimFiled($claim));

        return $claim;
    }
}
