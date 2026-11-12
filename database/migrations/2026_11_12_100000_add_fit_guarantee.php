<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Panmotive Fit Guarantee: remember which vehicle the buyer was shopping for and whether
 * the seller listed the part for it at the moment of purchase (that is the promise), and
 * mark returns made under the guarantee so they can't be refused and count against the
 * seller's fitment record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedBigInteger('fit_vehicle_id')->nullable();
            $table->string('fit_vehicle_label')->nullable();
            $table->boolean('fit_confirmed')->default(false);
        });

        Schema::table('return_requests', function (Blueprint $table) {
            $table->boolean('fit_guarantee')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn(['fit_vehicle_id', 'fit_vehicle_label', 'fit_confirmed']));
        Schema::table('return_requests', fn (Blueprint $table) => $table->dropColumn('fit_guarantee'));
    }
};
