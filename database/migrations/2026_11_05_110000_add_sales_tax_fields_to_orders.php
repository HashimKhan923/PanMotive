<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('shipping_tax', 10, 2)->default(0)->after('tax_total');
            $table->string('tax_calculation_id')->nullable()->after('shipping_tax');
            $table->string('tax_transaction_id')->nullable()->after('tax_calculation_id');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('tax_amount', 10, 2)->default(0)->after('line_total');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['shipping_tax', 'tax_calculation_id', 'tax_transaction_id']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('tax_amount');
        });
    }
};
