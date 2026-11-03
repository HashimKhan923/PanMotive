<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Listings sold by option are stocked per option. Bring their headline quantity in
     * line with the sum of their active options (kept in sync automatically from now on).
     */
    public function up(): void
    {
        $totals = DB::table('product_variants')->where('is_active', true)
            ->groupBy('product_id')->selectRaw('product_id, SUM(quantity) as total')->pluck('total', 'product_id');

        foreach ($totals as $productId => $total) {
            DB::table('products')->where('id', $productId)->update(['quantity' => (int) $total]);
        }
    }

    public function down(): void
    {
        // Data-only — nothing to reverse.
    }
};
