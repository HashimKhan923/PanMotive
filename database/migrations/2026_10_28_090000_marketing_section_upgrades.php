<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Buyer opt-outs. seller_id NULL = every store's marketing on Pan Motive.
        Schema::create('marketing_unsubscribes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('source', 30)->default('link'); // link | one_click | preferences
            $table->timestamps();
            $table->unique(['user_id', 'seller_id']);
        });

        // SMS marketing needs explicit opt-in (TCPA); no timestamp = never text them.
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('sms_opt_in_at')->nullable();
        });

        // One dated log of marketing clicks and attributed orders, so Insights can answer
        // "last 7 / 30 / 90 days" for every channel instead of showing lifetime counters.
        Schema::create('marketing_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel', 30);   // email | automation | promo_link | social | ads | affiliate | offsite_ads
            $table->string('kind', 12);      // click | conversion
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('revenue', 12, 2)->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['seller_id', 'kind', 'created_at']);
        });

        // Affiliate commission withheld from the seller's payout (like offsite_ad_fee).
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('affiliate_fee', 10, 2)->default(0)->after('platform_fee_amount');
        });

        // Store-wide sales: a % markdown on chosen listings for a date range (Etsy "Sales", eBay markdowns).
        Schema::create('sale_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 80);
            $table->decimal('percent_off', 5, 2);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status', 16)->default('scheduled'); // scheduled | active | ended | cancelled
            $table->timestamps();
        });

        Schema::create('sale_event_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('sale_price', 12, 2);
            // What the listing had before, so ending or cancelling the sale puts it back exactly.
            $table->decimal('previous_sale_price', 12, 2)->nullable();
            $table->timestamp('previous_sale_starts_at')->nullable();
            $table->timestamp('previous_deal_ends_at')->nullable();
            $table->unique(['sale_event_id', 'product_id']);
        });

        // Social: real click tracking per post/channel; announcements: follower ping cooldown.
        Schema::table('social_post_targets', function (Blueprint $table) {
            $table->foreignId('promo_link_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->timestamp('announcement_notified_at')->nullable();
        });

        // Clean out simulated social data — fake "published" posts and random engagement.
        DB::table('social_post_targets')->where('external_id', 'like', 'sim_%')->update([
            'status' => 'share_kit', 'external_id' => null, 'permalink' => null, 'metrics' => null, 'published_at' => null,
        ]);
        // Tokens are encrypted at rest, so find the fake "demo mode" connections by their meta flag.
        DB::table('social_accounts')->get(['id', 'meta'])
            ->filter(fn ($a) => in_array(json_decode((string) $a->meta, true)['mode'] ?? null, ['demo', 'oauth'], true))
            ->each(fn ($a) => DB::table('social_accounts')->where('id', $a->id)->delete());

        // Backfill dated events from the per-recipient tables that do carry timestamps.
        $this->backfill();
    }

    private function backfill(): void
    {
        $rows = [];
        foreach (DB::table('campaign_recipients')->join('marketing_campaigns', 'marketing_campaigns.id', '=', 'campaign_recipients.marketing_campaign_id')
            ->get(['marketing_campaigns.seller_id', 'campaign_recipients.*']) as $r) {
            if ($r->clicked_at) {
                $rows[] = ['seller_id' => $r->seller_id, 'channel' => 'email', 'kind' => 'click', 'ref_id' => $r->marketing_campaign_id, 'order_id' => null, 'revenue' => 0, 'created_at' => $r->clicked_at];
            }
            if ($r->converted_order_id) {
                $rows[] = ['seller_id' => $r->seller_id, 'channel' => 'email', 'kind' => 'conversion', 'ref_id' => $r->marketing_campaign_id, 'order_id' => $r->converted_order_id, 'revenue' => $r->conversion_value ?? 0, 'created_at' => $r->updated_at];
            }
        }
        foreach (DB::table('automation_sends')->join('marketing_automations', 'marketing_automations.id', '=', 'automation_sends.marketing_automation_id')
            ->get(['marketing_automations.seller_id', 'automation_sends.*']) as $r) {
            if ($r->clicked_at) {
                $rows[] = ['seller_id' => $r->seller_id, 'channel' => 'automation', 'kind' => 'click', 'ref_id' => $r->marketing_automation_id, 'order_id' => null, 'revenue' => 0, 'created_at' => $r->clicked_at];
            }
            if ($r->converted_order_id) {
                $rows[] = ['seller_id' => $r->seller_id, 'channel' => 'automation', 'kind' => 'conversion', 'ref_id' => $r->marketing_automation_id, 'order_id' => $r->converted_order_id, 'revenue' => $r->conversion_value ?? 0, 'created_at' => $r->updated_at];
            }
        }
        foreach (DB::table('affiliate_conversions')->join('affiliates', 'affiliates.id', '=', 'affiliate_conversions.affiliate_id')
            ->join('affiliate_programs', 'affiliate_programs.id', '=', 'affiliates.affiliate_program_id')
            ->get(['affiliate_programs.seller_id', 'affiliate_conversions.*']) as $r) {
            $rows[] = ['seller_id' => $r->seller_id, 'channel' => 'affiliate', 'kind' => 'conversion', 'ref_id' => $r->affiliate_id, 'order_id' => $r->order_id, 'revenue' => $r->order_value, 'created_at' => $r->created_at];
        }
        if (Schema::hasTable('offsite_ad_clicks')) {
            foreach (DB::table('offsite_ad_clicks')->get() as $r) {
                $rows[] = ['seller_id' => $r->seller_id, 'channel' => 'offsite_ads', 'kind' => 'click', 'ref_id' => $r->id, 'order_id' => null, 'revenue' => 0, 'created_at' => $r->created_at];
            }
            foreach (DB::table('offsite_ad_attributions')->get() as $r) {
                $rows[] = ['seller_id' => $r->seller_id, 'channel' => 'offsite_ads', 'kind' => 'conversion', 'ref_id' => $r->id, 'order_id' => $r->order_id, 'revenue' => $r->attributed_sales, 'created_at' => $r->created_at];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('marketing_events')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::table('seller_profiles', fn (Blueprint $t) => $t->dropColumn('announcement_notified_at'));
        Schema::table('social_post_targets', function (Blueprint $t) {
            $t->dropConstrainedForeignId('promo_link_id');
        });
        Schema::dropIfExists('sale_event_products');
        Schema::dropIfExists('sale_events');
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn('affiliate_fee'));
        Schema::dropIfExists('marketing_events');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('sms_opt_in_at'));
        Schema::dropIfExists('marketing_unsubscribes');
    }
};
