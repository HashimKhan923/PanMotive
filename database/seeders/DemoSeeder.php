<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Answer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Question;
use App\Models\Review;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Realistic demo catalog so the storefront isn't empty. Idempotent-ish
 * (firstOrCreate on lookups). Safe to run repeatedly.
 *
 *   php artisan db:seed --class=Database\\Seeders\\DemoSeeder
 */
class DemoSeeder extends Seeder
{
    private string $password = 'password';

    public function run(): void
    {
        $this->call([RoleSeeder::class, AdminPermissionSeeder::class, VehicleMakeSeeder::class, CategorySeeder::class]);

        // A public server must never have logins with a well-known password.
        $firstRun = ! User::where('email', 'seller1@panmotive.test')->exists();
        if (app()->environment('production')) {
            $this->password = Str::password(20, symbols: false);
        }

        Product::withoutSyncingToSearch(function () {
            $brands = $this->brands();
            $catTree = $this->categories();
            $vehicles = $this->vehicles();
            $attrs = $this->attributes();
            $sellers = $this->sellers();
            $products = $this->products($brands, $catTree, $vehicles, $attrs, $sellers);
            $this->orders($products, $sellers);
            $this->flashDeals($products);
            $this->reviewsAndQuestions($sellers);
            $this->advancedSellerFeatures($products, $sellers);
        });

        $this->command?->info('Demo catalog seeded.');
        if ($firstRun) {
            $this->command?->line("Demo logins seller1-3@panmotive.test and buyer1-6@panmotive.test, password: {$this->password}");
        }
    }

    private function brands(): array
    {
        $names = ['Bosch', 'Denso', 'ACDelco', 'Moog', 'NGK', 'Brembo', 'Gates', 'Monroe', 'K&N', 'Dorman', 'Motorcraft', 'Febi'];

        return collect($names)->mapWithKeys(fn ($n) => [
            $n => Brand::firstOrCreate(['slug' => Str::slug($n)], ['name' => $n]),
        ])->all();
    }

    private function categories(): array
    {
        return Category::whereNotNull('parent_id')->get()->keyBy('name')->all();
    }

    private function vehicles(): array
    {
        return Vehicle::whereHas('make', fn ($q) => $q->whereIn('slug', ['toyota', 'honda', 'ford', 'chevrolet']))
            ->whereBetween('year', [2016, 2021])->get()->all();
    }

    private function attributes(): array
    {
        $defs = [
            'Position' => ['Front', 'Rear', 'Front Left', 'Front Right'],
            'Material' => ['Ceramic', 'Semi-Metallic', 'Organic'],
            'Warranty' => ['1 Year', '2 Year', 'Lifetime'],
        ];
        $out = [];
        foreach ($defs as $name => $values) {
            $a = Attribute::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name]);
            $out[$name] = collect($values)->map(fn ($v) => AttributeValue::firstOrCreate(['attribute_id' => $a->id, 'value' => $v]))->all();
        }

        return $out;
    }

    private function sellers(): array
    {
        $defs = [
            ['AutoPro Parts Warehouse', 'seller1@panmotive.test'],
            ['Junkyard Genuine', 'seller2@panmotive.test'],
            ['OEM Direct Supply', 'seller3@panmotive.test'],
        ];

        return collect($defs)->map(function ($def) {
            [$biz, $email] = $def;
            $user = User::firstOrCreate(
                ['email' => $email],
                ['name' => $biz, 'password' => Hash::make($this->password), 'is_active' => true]
            );
            $user->assignRole('seller');
            SellerProfile::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'business_name' => $biz, 'business_type' => 'llc',
                    'status' => 'approved', 'kyc_status' => 'verified',
                    'stripe_account_id' => 'acct_demo_'.Str::random(8),
                    'stripe_onboarding_complete' => true,
                    'rating' => round(mt_rand(40, 50) / 10, 1),
                    'rating_count' => mt_rand(12, 240),
                ]
            );

            return $user;
        })->all();
    }

    private function products(array $brands, array $cats, array $vehicles, array $attrs, array $sellers): array
    {
        $catalog = [
            ['Brake Pads', 'Bosch', 'Ceramic Brake Pad Set', 'BP', 34.99, true, 'Material'],
            ['Brake Pads', 'Brembo', 'Performance Ceramic Brake Pads', 'BP', 59.99, false, 'Material'],
            ['Brake Rotors', 'ACDelco', 'Vented Front Brake Rotor', 'BR', 48.50, true, 'Position'],
            ['Brake Rotors', 'Bosch', 'QuietCast Brake Rotor', 'BR', 42.00, false, 'Position'],
            ['Calipers', 'ACDelco', 'Remanufactured Brake Caliper', 'BC', 79.99, false, 'Position'],
            ['Spark Plugs', 'NGK', 'Iridium IX Spark Plug (4-pack)', 'SP', 27.96, true, null],
            ['Spark Plugs', 'Denso', 'Platinum TT Spark Plug', 'SP', 8.49, false, null],
            ['Belts', 'Gates', 'Serpentine Belt', 'SB', 24.99, true, 'Warranty'],
            ['Belts', 'Dorman', 'Timing Belt Kit', 'TB', 89.00, false, 'Warranty'],
            ['Filters', 'K&N', 'High-Flow Air Filter', 'AF', 54.99, false, null],
            ['Filters', 'Bosch', 'Premium Oil Filter', 'OF', 9.99, true, null],
            ['Filters', 'Febi', 'Cabin Air Filter', 'CF', 14.50, false, null],
            ['Shocks & Struts', 'Monroe', 'OESpectrum Strut Assembly', 'ST', 119.99, false, 'Position'],
            ['Shocks & Struts', 'Monroe', 'Gas-Magnum Shock Absorber', 'SH', 44.99, true, 'Position'],
            ['Control Arms', 'Moog', 'Front Lower Control Arm', 'CA', 74.99, false, 'Position'],
            ['Ball Joints', 'Moog', 'Problem Solver Ball Joint', 'BJ', 32.99, true, 'Position'],
            ['Batteries', 'ACDelco', 'AGM Automotive Battery Group 48', 'BT', 189.99, false, 'Warranty'],
            ['Alternators', 'Denso', 'Remanufactured Alternator 130A', 'AL', 168.00, false, 'Warranty'],
            ['Starters', 'Bosch', 'Remanufactured Starter Motor', 'SM', 112.50, true, 'Warranty'],
            ['Sensors', 'Denso', 'Oxygen Sensor (Downstream)', 'O2', 61.99, false, null],
            ['Sensors', 'Bosch', 'Mass Air Flow Sensor', 'MAF', 88.00, false, null],
            ['Radiators', 'Denso', 'OE-Replacement Radiator', 'RD', 142.00, false, 'Warranty'],
            ['Water Pumps', 'Gates', 'Engine Water Pump', 'WP', 47.99, true, 'Warranty'],
            ['Thermostats', 'Motorcraft', 'Engine Coolant Thermostat', 'TH', 16.99, false, null],
        ];

        $products = [];
        foreach ($catalog as $i => [$catName, $brandName, $title, $prefix, $price, $onSale, $attrName]) {
            $seller = $sellers[$i % count($sellers)];
            $slug = Str::slug($title).'-'.$prefix.($i + 100);
            $partNo = strtoupper($prefix).'-'.mt_rand(1000, 9999);

            $product = Product::updateOrCreate(
                ['slug' => $slug],
                [
                    'seller_id' => $seller->id,
                    'category_id' => $cats[$catName]->id,
                    'brand_id' => $brands[$brandName]->id,
                    'title' => "{$brandName} {$title}",
                    'sku' => $partNo,
                    'part_number' => $partNo,
                    'oem_number' => mt_rand(0, 1) ? strtoupper(Str::random(3)).mt_rand(10000, 99999) : null,
                    'description' => "Direct-fit {$title} from {$brandName}. Engineered to meet or exceed OEM specifications for fit, "
                        ."performance and durability. Backed by seller support and a hassle-free 30-day return window.",
                    'bullet_points' => ['Direct OEM-style fit', 'Meets or exceeds OE specs', "Genuine {$brandName} quality", 'Fast US shipping'],
                    'price' => $price,
                    'sale_price' => $onSale ? round($price * 0.82, 2) : null,
                    'cost_price' => round($price * 0.55, 2),
                    'quantity' => [0, 2, 4, 12, 25, 40, 60][mt_rand(0, 6)],
                    'weight_lbs' => round(mt_rand(5, 180) / 10, 1),
                    'condition' => ['new', 'new', 'new', 'refurbished', 'used'][mt_rand(0, 4)],
                    'status' => 'active',
                    'published_at' => now()->subDays(mt_rand(0, 40)),
                    'views_count' => mt_rand(20, 4000),
                ]
            );

            // fitments — 3–7 random vehicles
            $fits = collect($vehicles)->random(min(mt_rand(3, 7), count($vehicles)));
            $product->fitments()->syncWithoutDetaching(
                $fits->mapWithKeys(fn ($v) => [$v->id => ['position' => $attrName === 'Position' ? ['Front', 'Rear'][mt_rand(0, 1)] : null]])->all()
            );

            // attributes
            if ($attrName && isset($attrs[$attrName])) {
                $product->attributeValues()->syncWithoutDetaching([$attrs[$attrName][array_rand($attrs[$attrName])]->id]);
            }
            $product->attributeValues()->syncWithoutDetaching([$attrs['Warranty'][array_rand($attrs['Warranty'])]->id]);

            // image
            if (! $product->hasMedia('images')) {
                try {
                    $product->addMediaFromString($this->placeholderPng($product->title, $catName))
                        ->usingFileName($slug.'.png')
                        ->toMediaCollection('images');
                } catch (\Throwable $e) {
                    // conversions may fail without an image driver — original still stored
                }
            }

            $products[] = $product;
        }

        return $products;
    }

    private function orders(array $products, array $sellers): void
    {
        $buyers = collect(range(1, 6))->map(fn ($n) => User::firstOrCreate(
            ['email' => "buyer{$n}@panmotive.test"],
            ['name' => "Demo Buyer {$n}", 'password' => Hash::make($this->password), 'is_active' => true]
        ))->all();

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

    private function placeholderPng(string $title, string $category): string
    {
        $w = 800;
        $h = 800;
        $im = imagecreatetruecolor($w, $h);
        [$r, $g, $b] = [[15, 23, 42], [30, 41, 59], [51, 65, 85], [11, 27, 52]][crc32($category) % 4];
        imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
        $amber = imagecolorallocate($im, 245, 165, 36);
        $white = imagecolorallocate($im, 255, 255, 255);

        imagesetthickness($im, 8);
        imagearc($im, $w / 2, $h / 2 - 40, 320, 320, 0, 360, $amber);
        imagefilledellipse($im, $w / 2, $h / 2 - 40, 90, 90, $amber);

        $label = strtoupper(substr($category, 0, 18));
        imagestring($im, 5, (int) (($w - strlen($label) * 9) / 2), $h - 120, $label, $white);
        $sub = substr($title, 0, 28);
        imagestring($im, 3, (int) (($w - strlen($sub) * 7) / 2), $h - 90, $sub, $amber);

        ob_start();
        imagepng($im);
        $data = ob_get_clean();
        imagedestroy($im);

        return $data;
    }
}
