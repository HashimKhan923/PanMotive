<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_policies', function (Blueprint $table) {
            // eBay-style "flat rate + $X for each additional item".
            $table->decimal('additional_item_rate', 8, 2)->nullable()->after('flat_rate');
            // US state codes this policy won't ship to (e.g. CA for CARB-restricted parts, AK/HI).
            $table->json('excluded_states')->nullable()->after('region_surcharges');
        });

        // The old Alaska/Hawaii switch was never enforced — carry its intent into the new list.
        foreach (DB::table('shipping_policies')->where('no_ship_alaska_hawaii', true)->pluck('id') as $id) {
            DB::table('shipping_policies')->where('id', $id)->update(['excluded_states' => json_encode(['AK', 'HI'])]);
        }
    }

    public function down(): void
    {
        Schema::table('shipping_policies', function (Blueprint $table) {
            $table->dropColumn(['additional_item_rate', 'excluded_states']);
        });
    }
};
