<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Models\Answer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Question;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Everything sample: stores, customers and products from their own seeders, plus orders,
 * reviews, questions, messages and promotions. Safe to run repeatedly.
 *
 *   php artisan db:seed --class=Database\\Seeders\\DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([CustomerSeeder::class, ProductSeeder::class]);

        Product::withoutSyncingToSearch(function () {
            $sellers = User::whereIn('email', SellerSeeder::EMAILS)->orderBy('email')->get()->all();
            $products = Product::whereIn('seller_id', collect($sellers)->pluck('id'))->where('status', 'active')->get()->all();

            $this->orders($products, $sellers);
            $this->flashDeals($products);
            $this->reviewsAndQuestions($sellers);
            $this->advancedSellerFeatures($products, $sellers);
        });

        $this->command?->info('Demo catalog seeded.');
    }

    private function orders(array $products, array $sellers): void
    {
        $buyers = User::where('email', 'like', 'buyer%@panmotive.test')->get()->all();

        if (Order::where('order_number', 'like', 'PM-DEMO%')->exists()) {
            return;
        }

        for ($o = 0; $o < 12; $o++) {
            $buyer = $buyers[array_rand($buyers)];
            $picks = collect($products)->random(mt_rand(1, 3));
            $subtotal = 0;

            $order = Order::create([
                'order_number' => 'PM-DEMO'.str_pad($o + 1, 3, '0', STR_PAD_LEFT),
                'buyer_id' => $buyer->id,
                'status' => OrderStatus::Delivered,
                'subtotal' => 0, 'total' => 0, 'currency' => 'USD',
                'shipping_address' => ['name' => $buyer->name, 'line1' => '100 Demo St', 'city' => 'Detroit', 'state' => 'MI', 'postal_code' => '48201', 'country' => 'US'],
                'billing_address' => ['name' => $buyer->name, 'line1' => '100 Demo St', 'city' => 'Detroit', 'state' => 'MI', 'postal_code' => '48201', 'country' => 'US'],
                'stripe_payment_intent_id' => 'pi_demo_'.Str::random(12),
                'placed_at' => now()->subDays(mt_rand(10, 60)),
                'delivered_at' => now()->subDays(mt_rand(1, 9)),
            ]);

            foreach ($picks as $p) {
                $qty = mt_rand(1, 2);
                $unit = (float) ($p->sale_price ?? $p->price);
                $line = round($unit * $qty, 2);
                $subtotal += $line;
                $split = OrderItem::calculateCommission($line, 10);

                $order->items()->create([
                    'seller_id' => $p->seller_id, 'product_id' => $p->id,
                    'product_title' => $p->title, 'product_sku' => $p->sku,
                    'quantity' => $qty, 'unit_price' => $unit, 'line_total' => $line,
                    'commission_rate' => 10,
                    'platform_fee_amount' => $split['platform_fee_amount'],
                    'seller_payout_amount' => $split['seller_payout_amount'],
                    'status' => OrderStatus::PayoutReleased,
                    'shipped_at' => now()->subDays(mt_rand(6, 12)),
                    'delivered_at' => $order->delivered_at,
                    'payout_released_at' => $order->delivered_at,
                    'carrier' => ['USPS', 'UPS', 'FedEx'][mt_rand(0, 2)],
                    'tracking_number' => strtoupper(Str::random(2)).mt_rand(100000000, 999999999),
                ]);
            }

            $order->update(['subtotal' => $subtotal, 'total' => $subtotal]);
        }
    }

    /** Give a few on-sale products a live countdown so the Deals page has timers. */
    private function flashDeals(array $products): void
    {
        $onSale = collect($products)->filter(fn ($p) => $p->sale_price && $p->sale_price < $p->price)->values();

        foreach ($onSale->take(6) as $i => $p) {
            $p->forceFill(['deal_ends_at' => now()->addHours(6 + $i * 9)])->saveQuietly();
        }
    }

    /** Verified reviews on purchased items + a handful of Q&A threads per catalog. */
    private function reviewsAndQuestions(array $sellers): void
    {
        if (Review::query()->exists() || Question::query()->exists()) {
            return;
        }

        $blurbs = [
            [5, 'Exact fit, no surprises', 'Bolted right on. Quality feels OEM or better and shipping was quick.'],
            [5, 'Great value', 'Same part the dealer wanted double for. Works perfectly after 500 miles.'],
            [4, 'Solid, minor hiccup', 'Works great. Instructions were a little thin but any DIYer will manage.'],
            [4, 'Would buy again', 'Good packaging, correct part, fair price. Knocked one star for slow carrier.'],
            [3, 'Does the job', 'Fitment is fine but the finish is not as nice as the photos suggest.'],
        ];

        $sellerIds = collect($sellers)->pluck('user_id')->all();

        OrderItem::with('order', 'product')
            ->whereIn('status', ['delivered', 'payout_released'])
            ->get()
            ->groupBy(fn ($item) => $item->product_id.'-'.$item->order->buyer_id)
            ->each(function ($group) use ($blurbs) {
                $item = $group->first();
                if (! $item->product || ! $item->order->buyer_id || mt_rand(1, 100) > 70) {
                    return;
                }

                [$rating, $title, $body] = $blurbs[array_rand($blurbs)];

                Review::updateOrCreate(
                    ['product_id' => $item->product_id, 'user_id' => $item->order->buyer_id],
                    [
                        'order_item_id' => $item->id,
                        'rating' => $rating,
                        'title' => $title,
                        'body' => $body,
                        'is_verified' => true,
                        'status' => 'published',
                        'helpful_count' => mt_rand(0, 14),
                    ]
                );
            });

        $buyerIds = User::where('email', 'like', 'buyer%@panmotive.test')->pluck('id')->all();
        $qBank = [
            'Does this fit a 2019 model with the 1.5L turbo?',
            'Is hardware included or do I need to reuse the old bolts?',
            'What is the expected lifespan on this part?',
            'Will this work with an automatic transmission?',
            'Is this OEM or aftermarket?',
        ];
        $aBank = [
            'Yes — it is listed for that year and engine. Check the fitment table on the listing to be sure.',
            'Mounting hardware is included in the box.',
            'Most customers get well over 50k miles out of it with normal driving.',
            'Yes, it is compatible with both automatic and manual.',
            'This is a quality aftermarket part built to OEM spec.',
        ];

        Product::where('status', 'active')->inRandomOrder()->take(12)->get()->each(function ($product) use ($buyerIds, $sellerIds, $qBank, $aBank) {
            foreach (range(1, mt_rand(1, 2)) as $n) {
                $i = array_rand($qBank);
                $question = Question::create([
                    'product_id' => $product->id,
                    'user_id' => $buyerIds ? $buyerIds[array_rand($buyerIds)] : null,
                    'body' => $qBank[$i],
                    'status' => 'published',
                    'created_at' => now()->subDays(mt_rand(2, 40)),
                ]);

                if (mt_rand(1, 100) <= 75) {
                    $bySeller = mt_rand(1, 100) <= 60;
                    Answer::create([
                        'question_id' => $question->id,
                        'user_id' => $bySeller ? $product->seller_id : ($buyerIds ? $buyerIds[array_rand($buyerIds)] : null),
                        'is_seller' => $bySeller,
                        'body' => $aBank[$i],
                        'created_at' => $question->created_at->addDays(1),
                    ]);
                }
            }
        });

        // Ratings roll-ups
        Product::has('reviews')->get()->each->recalculateRatings();
    }

    /**
     * Variants, seller promotions, conversations and review responses so the
     * advanced portal screens aren't empty.
     */
    private function advancedSellerFeatures(array $products, array $sellers): void
    {
        $sellerUsers = collect($sellers);
        $buyerIds = \App\Models\User::where('email', 'like', 'buyer%@panmotive.test')->pluck('id')->all();

        // --- Variants on a few products ---
        if (! \App\Models\ProductVariant::query()->exists()) {
            foreach (collect($products)->random(min(5, count($products))) as $product) {
                $base = (float) $product->price;
                $sets = [
                    [['Front axle', 0], ['Rear axle', -8]],
                    [['Left side', 0], ['Right side', 0]],
                    [['Pack of 2', 0], ['Pack of 4', $base * 0.9]],
                ];
                foreach ($sets[array_rand($sets)] as $i => [$label, $delta]) {
                    $product->variants()->create([
                        'label' => $label,
                        'options' => ['Option' => $label],
                        'sku' => strtoupper(Str::slug($label)).'-'.$product->id,
                        'price' => $delta ? round($base + $delta, 2) : null,
                        'quantity' => mt_rand(0, 20),
                        'sort_order' => $i,
                        'is_active' => true,
                    ]);
                }
            }
        }

        // --- Seller promotions ---
        if (! \App\Models\Coupon::whereNotNull('seller_id')->exists()) {
            foreach ($sellerUsers as $n => $seller) {
                \App\Models\Coupon::create([
                    'code' => 'STORE'.($n + 1).'SAVE',
                    'type' => 'percentage', 'value' => [10, 15, 20][$n % 3],
                    'min_order_total' => [0, 50, 75][$n % 3],
                    'usage_limit_per_user' => 1,
                    'seller_id' => $seller->id,
                    'expires_at' => now()->addDays(30),
                    'is_active' => true,
                ]);
            }
        }

        // --- Conversations ---
        if (! \App\Models\Conversation::query()->exists() && $buyerIds) {
            $messaging = app(\App\Services\MessagingService::class);
            foreach (collect($products)->random(min(6, count($products))) as $product) {
                $buyer = \App\Models\User::find($buyerIds[array_rand($buyerIds)]);
                $seller = \App\Models\User::find($product->seller_id);
                if (! $buyer || ! $seller || $buyer->id === $seller->id) {
                    continue;
                }
                $c = $messaging->threadFor($buyer, $seller, $product);
                $messaging->send($c, $buyer, 'Hi — will this fit my 2018 model? Also, how soon does it ship?');
                if (mt_rand(0, 1)) {
                    $messaging->send($c, $seller, 'Yes it fits 2016–2020, and it ships same day if ordered before 2pm ET.');
                }
            }
        }

        // --- Review responses + one seller on vacation ---
        \App\Models\Review::whereNull('seller_response')->where('rating', '<=', 3)->take(4)->get()
            ->each(fn ($r) => $r->update([
                'seller_response' => 'Thanks for the feedback — please reach out and we\'ll make this right.',
                'seller_responded_at' => now()->subDays(mt_rand(1, 10)),
            ]));

        $sellerUsers->first()?->sellerProfile?->update([
            'bio' => 'Family-run since 2009. We specialise in OEM-grade brake, suspension and drivetrain parts with fast nationwide shipping.',
            'return_policy' => "30-day returns on unused parts in original packaging. Buyer covers return shipping unless the item is defective or incorrectly described.",
            'handling_fee' => 0,
        ]);
        $sellerUsers->get(2)?->sellerProfile?->update([
            'vacation_mode' => true,
            'vacation_message' => 'On a parts-buying trip — orders placed now ship starting next Monday. Thanks for your patience!',
        ]);

        $this->growthFeatures($products, $sellerUsers);
    }

    /** Tiers, ad credit + boosts, followers, offers, promo links, featured products. */
    private function growthFeatures(array $products, $sellerUsers): void
    {
        $buyerIds = \App\Models\User::where('email', 'like', 'buyer%@panmotive.test')->pluck('id')->all();
        $boostService = app(\App\Services\Seller\BoostService::class);
        $scorecard = app(\App\Services\Seller\SellerScorecardService::class);

        foreach ($sellerUsers as $seller) {
            if ($seller->sellerProfile->ad_credit <= 0) {
                $boostService->grantCredit($seller, 50.00, 'Welcome ad credit', 'promo_grant');
            }
            $scorecard->refreshTier($seller);

            $mine = collect($products)->where('seller_id', $seller->id)->where('status', 'active');

            // Feature up to 3 products + accept offers on a couple.
            $seller->sellerProfile->update(['featured_product_ids' => $mine->take(3)->pluck('id')->all()]);
            $mine->take(2)->each(fn ($p) => $p->update(['accepts_offers' => true, 'min_offer_price' => round($p->price * 0.6, 2)]));

            // One boost per seller.
            if ($mine->isNotEmpty() && ! \App\Models\Boost::where('seller_id', $seller->id)->exists()) {
                try {
                    $boostService->start($seller, $mine->first(), ['search', 'category', 'home'][$seller->id % 3], 7);
                } catch (\Throwable $e) {
                    // ignore in seeding
                }
            }

            // A tracked link.
            if (! \App\Models\PromoLink::where('seller_id', $seller->id)->exists()) {
                \App\Models\PromoLink::create([
                    'seller_id' => $seller->id,
                    'product_id' => $mine->first()?->id,
                    'label' => 'Social bio link',
                    'clicks' => mt_rand(20, 400),
                    'orders' => mt_rand(1, 20),
                    'revenue' => mt_rand(80, 3000),
                ]);
            }
        }

        // Buyers follow stores + send a couple of offers.
        if ($buyerIds && ! \App\Models\Offer::query()->exists()) {
            foreach ($sellerUsers as $seller) {
                foreach (array_slice($buyerIds, 0, mt_rand(2, 5)) as $bid) {
                    \DB::table('seller_followers')->insertOrIgnore([
                        'user_id' => $bid, 'seller_id' => $seller->id,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }

            $offerService = app(\App\Services\Seller\OfferService::class);
            foreach (\App\Models\Product::where('accepts_offers', true)->where('status', 'active')->take(5)->get() as $p) {
                $buyer = \App\Models\User::find($buyerIds[array_rand($buyerIds)]);
                if ($buyer && $buyer->id !== $p->seller_id) {
                    try {
                        $offerService->create($buyer, $p, round($p->effectivePrice() * 0.85, 2), 1, 'Any wiggle room on price?');
                    } catch (\Throwable $e) {
                    }
                }
            }
        }
    }

}
