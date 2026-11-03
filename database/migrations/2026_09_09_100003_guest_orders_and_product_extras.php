<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guest checkout: buyer_id becomes optional; capture contact on the order.
        if (! Schema::hasColumn('orders', 'guest_email')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('guest_email')->nullable()->after('buyer_id');
                $table->string('guest_name')->nullable()->after('guest_email');
            });
        }

        // Recover from a previously interrupted run of this migration.
        if (Schema::hasColumn('orders', 'buyer_id_old')) {
            Schema::disableForeignKeyConstraints();
            if (Schema::hasColumn('orders', 'buyer_id')) {
                \Illuminate\Support\Facades\DB::statement('UPDATE orders SET buyer_id = buyer_id_old WHERE buyer_id IS NULL');
                Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('buyer_id_old'));
            } else {
                \Illuminate\Support\Facades\DB::statement('ALTER TABLE orders RENAME COLUMN buyer_id_old TO buyer_id');
            }
            Schema::enableForeignKeyConstraints();
        }

        // Make buyer_id nullable (Laravel 12 rebuilds the SQLite table, preserving FKs).
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('buyer_id')->nullable()->change();
        });

        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'deal_ends_at')) {
                $table->timestamp('deal_ends_at')->nullable();
            }
            if (! Schema::hasColumn('products', 'avg_rating')) {
                $table->decimal('avg_rating', 3, 2)->default(0);
            }
            if (! Schema::hasColumn('products', 'reviews_count')) {
                $table->unsignedInteger('reviews_count')->default(0);
            }
        });

        if (! Schema::hasColumn('users', 'active_vehicle')) {
            Schema::table('users', function (Blueprint $table) {
                $table->json('active_vehicle')->nullable(); // "garage" — {id, label}
            });
        }
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['guest_email', 'guest_name']));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['deal_ends_at', 'avg_rating', 'reviews_count']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('active_vehicle'));
    }
};
