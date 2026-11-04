<?php

namespace App\Services\Seller;

use App\Enums\OrderStatus;
use App\Models\BuyerClaim;
use App\Models\OrderItem;
use App\Models\PolicyViolation;
use App\Models\User;

class AccountHealthService
{
    /**
     * A single "account standing" summary a seller sees at a glance.
     *
     * @return array{level:string, score:int, points:int, active:int, severe:int, claim_rate:float, open_claims:int, at_risk:bool}
     */
    public function standing(User $seller): array
    {
        $violations = PolicyViolation::where('seller_id', $seller->id)
            ->whereIn('status', ['active', 'appealed', 'upheld'])
            ->get();

        $points = $violations->sum(fn (PolicyViolation $v) => $v->weight());
        $severe = $violations->filter(fn ($v) => $v->isCounting() && $v->severity === 'severe')->count();

        // Last 12 months; denied/withdrawn claims and ones the seller refunded themselves don't count.
        $delivered = OrderItem::where('seller_id', $seller->id)
            ->whereIn('status', [OrderStatus::Delivered->value, OrderStatus::PayoutReleased->value])
            ->where('created_at', '>=', now()->subYear())
            ->count();
        $claims = BuyerClaim::where('seller_id', $seller->id)
            ->where('created_at', '>=', now()->subYear())
            ->countingAgainstSeller()->count();
        $claimRate = $delivered > 0 ? round($claims / $delivered * 100, 2) : 0.0;
        $openClaims = BuyerClaim::where('seller_id', $seller->id)->whereIn('status', ['open', 'seller_responded', 'escalated'])->count();

        // Score out of 100: start at 100, subtract violation points and claim-rate penalty.
        $score = (int) max(0, 100 - $points - min(40, $claimRate * 4));

        $level = match (true) {
            $severe > 0 || $score < 40 => 'at_risk',
            $score < 70 => 'needs_attention',
            default => 'good',
        };

        return [
            'level' => $level,
            'score' => $score,
            'points' => $points,
            'active' => $violations->count(),
            'severe' => $severe,
            'claim_rate' => $claimRate,
            'open_claims' => $openClaims,
            'at_risk' => $level === 'at_risk',
        ];
    }

    public function submitAppeal(PolicyViolation $violation, string $text): void
    {
        abort_unless($violation->status === 'active', 422);
        $violation->update([
            'status' => 'appealed',
            'appeal_text' => $text,
            'appeal_submitted_at' => now(),
        ]);
    }

    public function decideAppeal(PolicyViolation $violation, bool $uphold, User $admin): void
    {
        $violation->update([
            'status' => $uphold ? 'upheld' : 'lifted',
            'appeal_decided_by' => $admin->id,
            'appeal_decided_at' => now(),
        ]);
    }

    /** Seller answers a buyer claim. */
    public function respondToClaim(BuyerClaim $claim, string $response): void
    {
        abort_unless($claim->status === 'open', 422);
        $claim->update([
            'status' => 'seller_responded',
            'seller_response' => $response,
            'seller_responded_at' => now(),
        ]);
    }

    /**
     * Seller settles a claim by refunding the buyer (eBay's "issue a refund" on a case). Real money
     * back through the normal refund path, the claim closes, and it doesn't count against the store.
     */
    public function resolveWithRefund(BuyerClaim $claim, User $seller): float
    {
        abort_unless(in_array($claim->status, ['open', 'seller_responded'], true), 422, 'This claim is already with PanMotive or closed.');

        $item = $claim->orderItem;
        $refunded = app(\App\Services\Orders\BuyerRefundService::class)->refundLine(
            $item, 'claim', "Refund for claim {$claim->claim_number} on order {$item->order->order_number}", true
        );

        $item->update(['status' => OrderStatus::Refunded]);
        $item->order->syncStatusFromLines();
        $item->statusHistory()->create([
            'status' => OrderStatus::Refunded->value,
            'note' => "Claim {$claim->claim_number} resolved by the seller — refunded \$".number_format($refunded, 2),
            'changed_by' => $seller->id,
        ]);
        $claim->update([
            'status' => 'granted', 'decided_by' => $seller->id, 'decided_at' => now(),
            'resolution_note' => 'Seller refunded $'.number_format($refunded, 2),
        ]);
        $claim->buyer?->notify(new \App\Notifications\ClaimResolvedBySeller($claim, $refunded));

        return $refunded;
    }

    /** Escalate claims the seller left unanswered past the SLA. Returns count escalated. */
    public function escalateStaleClaims(): int
    {
        $count = 0;
        BuyerClaim::where('status', 'open')
            ->whereNotNull('respond_by')
            ->where('respond_by', '<', now())
            ->chunkById(200, function ($claims) use (&$count) {
                foreach ($claims as $claim) {
                    $claim->update(['status' => 'escalated']);
                    \App\Models\AdminAlert::create([
                        'type' => 'claim_escalated',
                        'message' => "Claim {$claim->claim_number} auto-escalated — seller did not respond in time.",
                        'related_order_item_id' => $claim->order_item_id,
                    ]);
                    // Unanswered claims are themselves a policy signal.
                    PolicyViolation::firstOrCreate(
                        ['seller_id' => $claim->seller_id, 'type' => 'unanswered_claim', 'order_item_id' => $claim->order_item_id],
                        ['severity' => 'warning', 'title' => 'Unanswered buyer claim', 'description' => "Claim {$claim->claim_number} was not answered within 72 hours.", 'status' => 'active', 'expires_at' => now()->addDays(90)],
                    );
                    $count++;
                }
            });

        return $count;
    }

    /** Lift time-boxed violations whose window has passed. Returns count lifted. */
    public function expireOldViolations(): int
    {
        return PolicyViolation::whereIn('status', ['active', 'appealed'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => 'lifted']);
    }
}
