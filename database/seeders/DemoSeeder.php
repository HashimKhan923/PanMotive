<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleEngine;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
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
    public function run(): void
    {
        $this->call([RoleSeeder::class, AdminPermissionSeeder::class]);

        Product::withoutSyncingToSearch(function () {
            $brands = $this->brands();
            $catTree = $this->categories();
            $vehicles = $this->vehicles();
            $attrs = $this->attributes();
            $sellers = $this->sellers();
            $products = $this->products($brands, $catTree, $vehicles, $attrs, $sellers);
            $this->orders($products, $sellers);
        });

        $this->command?->info('Demo catalog seeded.');
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
        $tree = [
            'Brakes' => ['Brake Pads', 'Brake Rotors', 'Calipers', 'Brake Lines'],
            'Engine' => ['Spark Plugs', 'Belts', 'Filters', 'Gaskets'],
            'Suspension' => ['Shocks & Struts', 'Control Arms', 'Ball Joints'],
            'Electrical' => ['Batteries', 'Alternators', 'Starters', 'Sensors'],
            'Cooling' => ['Radiators', 'Water Pumps', 'Thermostats'],
        ];

        $out = [];
        $order = 0;
        foreach ($tree as $parent => $kids) {
            $p = Category::firstOrCreate(['slug' => Str::slug($parent)], ['name' => $parent, 'sort_order' => $order++]);
            foreach ($kids as $k) {
                $out[$k] = Category::firstOrCreate(['slug' => Str::slug($k)], ['name' => $k, 'parent_id' => $p->id]);
            }
        }

        return $out;
    }

    private function vehicles(): array
    {
        $data = [
            'Toyota' => ['Camry' => [2018, 2019, 2020, 2021], 'Corolla' => [2017, 2018, 2019, 2020], 'RAV4' => [2019, 2020, 2021]],
            'Honda' => ['Civic' => [2016, 2017, 2018, 2019, 2020], 'Accord' => [2018, 2019, 2020], 'CR-V' => [2017, 2018, 2019]],
            'Ford' => ['F-150' => [2015, 2016, 2017, 2018, 2019, 2020], 'Escape' => [2017, 2018, 2019], 'Mustang' => [2015, 2016, 2017]],
            'Chevrolet' => ['Silverado 1500' => [2014, 2015, 2016, 2017, 2018], 'Equinox' => [2018, 2019, 2020], 'Malibu' => [2016, 2017, 2018]],
        ];
        $engines = collect(['2.0L L4', '2.5L L4', '3.5L V6', '5.0L V8', '1.5L L4 Turbo'])
            ->map(fn ($n) => VehicleEngine::firstOrCreate(['name' => $n]))->all();

        $vehicles = [];
        foreach ($data as $makeName => $models) {
            $make = VehicleMake::firstOrCreate(['slug' => Str::slug($makeName)], ['name' => $makeName]);
            foreach ($models as $modelName => $years) {
                $model = VehicleModel::firstOrCreate(
                    ['vehicle_make_id' => $make->id, 'slug' => Str::slug($modelName)],
                    ['name' => $modelName]
                );
                foreach ($years as $year) {
                    $vehicles[] = Vehicle::firstOrCreate([
                        'vehicle_model_id' => $model->id,
                        'year' => $year,
                        'vehicle_engine_id' => $engines[array_rand($engines)]->id,
                        'trim' => null,
                    ], ['vehicle_make_id' => $make->id]);
                }
            }
        }

        return $vehicles;
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
                ['name' => $biz, 'password' => Hash::make('password'), 'is_active' => true]
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
            ['name' => "Demo Buyer {$n}", 'password' => Hash::make('password'), 'is_active' => true]
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
