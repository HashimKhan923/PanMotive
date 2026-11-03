<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('seller_profiles', 'ad_credit')) {
            Schema::table('seller_profiles', function (Blueprint $table) {
                $table->decimal('ad_credit', 10, 2)->default(0)->after('free_shipping_threshold');
                $table->string('banner_path')->nullable()->after('logo_path');
                $table->string('announcement')->nullable()->after('banner_path');
                $table->timestamp('announcement_until')->nullable()->after('announcement');
                $table->json('featured_product_ids')->nullable()->after('announcement_until');
            });
        }

        if (! Schema::hasColumn('products', 'sale_starts_at')) {
            Schema::table('products', function (Blueprint $table) {
                $table->timestamp('sale_starts_at')->nullable()->after('deal_ends_at');
            });
        }

        // Buyers following a store.
        if (! Schema::hasTable('seller_followers')) {
            Schema::create('seller_followers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['user_id', 'seller_id']);
            });
        }

        // Paid placement campaigns ("Boost this listing").
        Schema::dropIfExists('boosts');
        Schema::create('boosts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->enum('placement', ['search', 'category', 'home'])->default('search');
            $table->unsignedInteger('days');
            $table->decimal('fee', 10, 2);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedInteger('attributed_orders')->default(0);
            $table->decimal('attributed_revenue', 12, 2)->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->enum('status', ['active', 'ended', 'cancelled'])->default('active');
            $table->timestamps();

            $table->index(['status', 'ends_at']);
            $table->index(['placement', 'status']);
        });

        // Seller ad-credit / boost ledger.
        Schema::create('ad_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->enum('type', ['topup', 'boost_spend', 'promo_grant', 'refund']);
            $table->decimal('amount', 10, 2);        // + credit, − spend
            $table->decimal('balance_after', 10, 2);
            $table->string('description');
            $table->nullableMorphs('source');
            $table->timestamps();
        });

        // Trackable share links.
        Schema::create('promo_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 16)->unique();
            $table->string('label')->nullable();
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedInteger('orders')->default(0);
            $table->decimal('revenue', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_links');
        Schema::dropIfExists('ad_transactions');
        Schema::dropIfExists('boosts');
        Schema::dropIfExists('seller_followers');
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('sale_starts_at'));
        Schema::table('seller_profiles', fn (Blueprint $t) => $t->dropColumn([
            'ad_credit', 'banner_path', 'announcement', 'announcement_until', 'featured_product_ids',
        ]));
    }
};
