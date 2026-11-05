<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Instant payouts used to be recorded even when Stripe failed. Those rows say a seller was paid when
 * no money moved: they stop the real payout and block refunds on the line. Each one is written to the
 * admin alerts first, so there is still a record of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach (DB::table('payout_advances')->whereNull('stripe_transfer_id')->get() as $advance) {
            $removable = $advance->status === 'advanced';

            DB::table('admin_alerts')->insert([
                'type' => $removable ? 'instant_payout_removed' : 'instant_payout_check',
                'message' => $removable
                    ? "Removed an instant payout of \${$advance->net_paid} on order item #{$advance->order_item_id}: Stripe never sent it. The normal payout will now run."
                    // Already marked settled, so the seller may be owed this money: a person has to look.
                    : "Check order item #{$advance->order_item_id}: its instant payout of \${$advance->net_paid} is marked {$advance->status}, but Stripe has no transfer for it. The seller may not have been paid.",
                'related_order_item_id' => $advance->order_item_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($removable) {
                DB::table('payout_advances')->where('id', $advance->id)->delete();
            }
        }
    }

    public function down(): void
    {
        // The removed rows recorded money that never moved; they are not put back.
    }
};
