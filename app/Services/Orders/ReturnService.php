<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\ReturnRequest;
use App\Models\User;
use App\Notifications\ReturnUpdated;
use App\Services\Seller\InventoryService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Seller-side return workflow with a strict order of steps:
 *   requested → approved → received → refunded
 *   requested → rejected
 *   requested → refunded  (refund without return — buyer keeps a low-value item)
 * Every step is logged on the return's timeline and the buyer is told.
 */
class ReturnService
{
    private const ALLOWED = [
        'approve' => ['requested'],
        'reject' => ['requested'],
        'receive' => ['approved'],
        'refund' => ['approved', 'received'],
        'refund_without_return' => ['requested', 'approved'],
    ];

    public function __construct(private BuyerRefundService $refunds, private InventoryService $inventory) {}

    public function canDo(ReturnRequest $rma, string $action): bool
    {
        return in_array($rma->status, self::ALLOWED[$action] ?? [], true);
    }

    /** @return float|null amount refunded, for refund actions */
    public function act(ReturnRequest $rma, string $action, User $actor, ?string $note = null, bool $restock = false): ?float
    {
        if (! $this->canDo($rma, $action)) {
            throw new RuntimeException('That step isn\'t available for a return that\'s '.$rma->status.'.');
        }
        if ($action === 'reject' && blank($note)) {
            throw new RuntimeException('Tell the buyer why the return is declined — they\'ll see it and can escalate to Pan Motive.');
        }

        $item = $rma->orderItem;
        $refunded = null;

        DB::transaction(function () use ($rma, $action, $actor, $note, $restock, $item, &$refunded) {
            switch ($action) {
                case 'approve':
                    $rma->update(['status' => 'approved', 'resolution_note' => $note]);
                    $item->update(['status' => OrderStatus::ReturnApproved]);
                    $rma->log('approved', 'seller', $actor->id, $note);
                    break;

                case 'reject':
                    $rma->update(['status' => 'rejected', 'resolution_note' => $note, 'resolved_by' => $actor->id, 'resolved_at' => now()]);
                    // Back to its previous resolved state so the payout can carry on.
                    $item->update(['status' => $item->payout_released_at ? OrderStatus::PayoutReleased : OrderStatus::Delivered]);
                    $rma->log('rejected', 'seller', $actor->id, $note);
                    break;

                case 'receive':
                    $rma->update(['status' => 'received']);
                    $rma->log('note', 'seller', $actor->id, 'Item received back'.($note ? ": $note" : ''));
                    if ($restock && $item->product) {
                        $variantExists = $item->product_variant_id
                            && $item->product->variants()->whereKey($item->product_variant_id)->exists();
                        if (! $item->product_variant_id || $variantExists) {
                            $this->inventory->adjust($item->product, (int) $item->quantity, 'return', [
                                'variant_id' => $variantExists ? $item->product_variant_id : null,
                                'reference' => $rma->rma_number,
                                'note' => 'Returned item back to stock',
                            ]);
                        }
                    }
                    break;

                case 'refund':
                case 'refund_without_return':
                    $refunded = $this->refunds->refundLine(
                        $item, 'return', "Refund for return {$rma->rma_number} on order {$item->order->order_number}"
                    );
                    $rma->update(['status' => 'refunded', 'resolution_note' => $note ?? $rma->resolution_note, 'resolved_by' => $actor->id, 'resolved_at' => now()]);
                    $item->update(['status' => OrderStatus::Refunded]);
                    $item->order->syncStatusFromLines();
                    $item->statusHistory()->create([
                        'status' => OrderStatus::Refunded->value,
                        'note' => "Return {$rma->rma_number} refunded \$".number_format($refunded, 2)
                            .($action === 'refund_without_return' ? ' — buyer keeps the item' : ''),
                        'changed_by' => $actor->id,
                    ]);
                    $rma->log('resolved', 'seller', $actor->id, 'Refund issued: $'.number_format($refunded, 2)
                        .($action === 'refund_without_return' ? ' (no return needed)' : '').($note ? " — $note" : ''));
                    break;
            }
        });

        $step = match ($action) {
            'approve' => 'approved', 'reject' => 'rejected', 'receive' => 'received', default => 'refunded',
        };
        $rma->user?->notify(new ReturnUpdated($rma->fresh('orderItem'), $step, $refunded));

        return $refunded;
    }
}
