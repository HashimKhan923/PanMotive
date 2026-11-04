<?php

namespace Database\Seeders;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sample parts: three per subcategory, each fitting its own makes, models and years, so category
 * pages and the vehicle selector show different results. Listings already there are left alone.
 *
 *   php artisan db:seed --class=ProductSeeder --force
 */
class ProductSeeder extends Seeder
{
    private const FITS = [
        'sedans' => ['Toyota' => ['Camry', 'Corolla'], 'Honda' => ['Civic', 'Accord'], 'Nissan' => ['Altima', 'Sentra'], 'Hyundai' => ['Elantra', 'Sonata'], 'Kia' => ['Forte']],
        'suvs' => ['Toyota' => ['RAV4', 'Highlander'], 'Honda' => ['CR-V', 'Pilot'], 'Ford' => ['Escape', 'Explorer'], 'Chevrolet' => ['Equinox', 'Traverse'], 'Nissan' => ['Rogue'], 'Hyundai' => ['Tucson', 'Santa Fe'], 'Subaru' => ['Outback', 'Forester']],
        'trucks' => ['Ford' => ['F-150'], 'Chevrolet' => ['Silverado 1500'], 'GMC' => ['Sierra 1500'], 'RAM' => ['1500'], 'Toyota' => ['Tacoma', 'Tundra'], 'Nissan' => ['Frontier']],
        'heavy' => ['Ford' => ['F-250 Super Duty'], 'Chevrolet' => ['Silverado 2500 HD'], 'RAM' => ['2500', '3500']],
        'muscle' => ['Ford' => ['Mustang'], 'Chevrolet' => ['Camaro'], 'Dodge' => ['Challenger', 'Charger'], 'Subaru' => ['WRX']],
        'jeep' => ['Jeep' => ['Wrangler', 'Gladiator', 'Grand Cherokee']],
        'euro' => ['BMW' => ['3 Series', '5 Series', 'X3'], 'Audi' => ['A4', 'Q5'], 'Mercedes-Benz' => ['C-Class', 'E-Class'], 'Volkswagen' => ['Jetta', 'Tiguan']],
    ];

    /** Subcategory, brand, title, price, on sale, fits, from year, to year, position. */
    private const PARTS = [
        ['Brake Pads', 'Bosch', 'QuietCast Ceramic Front Brake Pad Set', 42.99, true, 'sedans', 2014, 2023, 'Front'],
        ['Brake Pads', 'Power Stop', 'Z36 Truck & Tow Carbon-Ceramic Brake Pads', 79.99, false, 'trucks', 2015, 2024, 'Front'],
        ['Brake Pads', 'Brembo', 'Sport Performance Semi-Metallic Brake Pads', 94.50, false, 'muscle', 2015, 2023, 'Front'],
        ['Brake Rotors', 'ACDelco', 'Vented Front Brake Rotor', 58.50, true, 'trucks', 2014, 2022, 'Front'],
        ['Brake Rotors', 'Centric', 'Premium Coated Rear Brake Rotor', 46.00, false, 'suvs', 2015, 2024, 'Rear'],
        ['Brake Rotors', 'Brembo', 'Drilled Slotted Sport Brake Rotor', 129.99, false, 'euro', 2014, 2022, 'Front'],
        ['Calipers', 'Cardone', 'Remanufactured Front Brake Caliper', 84.99, false, 'sedans', 2012, 2020, 'Front'],
        ['Calipers', 'Raybestos', 'Remanufactured Rear Brake Caliper', 72.00, true, 'suvs', 2013, 2022, 'Rear'],
        ['Calipers', 'Brembo', 'Four-Piston Big Brake Caliper', 389.00, false, 'muscle', 2016, 2023, 'Front'],
        ['Brake Lines', 'Dorman', 'Stainless Front Brake Hose', 24.99, false, 'sedans', 2012, 2022, 'Front'],
        ['Brake Lines', 'Raybestos', 'Rear Hydraulic Brake Hose', 19.99, true, 'trucks', 2014, 2022, 'Rear'],
        ['Brake Lines', 'Goodridge', 'Braided Stainless Brake Line Kit', 119.00, false, 'jeep', 2012, 2024, null],

        ['Belts', 'Gates', 'Micro-V Serpentine Belt', 29.99, true, 'trucks', 2012, 2023, null],
        ['Belts', 'Dayco', 'Timing Belt and Water Pump Kit', 189.00, false, 'sedans', 2010, 2018, null],
        ['Belts', 'Continental', 'Poly-V Accessory Drive Belt', 26.50, false, 'euro', 2013, 2022, null],
        ['Filters', 'K&N', 'High-Flow Washable Air Filter', 59.99, false, 'muscle', 2015, 2023, null],
        ['Filters', 'Bosch', 'Premium Oil Filter', 9.99, true, 'sedans', 2012, 2025, null],
        ['Filters', 'Mann-Filter', 'Activated Carbon Cabin Air Filter', 27.50, false, 'euro', 2014, 2024, null],
        ['Spark Plugs', 'NGK', 'Iridium IX Spark Plug 4-Pack', 39.96, true, 'sedans', 2012, 2024, null],
        ['Spark Plugs', 'Denso', 'Iridium TT Spark Plug 6-Pack', 58.50, false, 'suvs', 2012, 2022, null],
        ['Spark Plugs', 'Motorcraft', 'Platinum Spark Plug 8-Pack', 62.00, false, 'trucks', 2011, 2020, null],
        ['Gaskets', 'Fel-Pro', 'Valve Cover Gasket Set', 34.99, false, 'trucks', 2012, 2022, null],
        ['Gaskets', 'Victor Reinz', 'Cylinder Head Gasket Set', 149.00, true, 'euro', 2012, 2020, null],
        ['Gaskets', 'Mahle', 'Oil Pan Gasket', 22.50, false, 'suvs', 2013, 2023, null],

        ['Shocks & Struts', 'Monroe', 'Quick-Strut Complete Strut Assembly', 139.99, true, 'sedans', 2012, 2021, 'Front'],
        ['Shocks & Struts', 'Bilstein', '5100 Series Lifted Truck Shock', 124.00, false, 'trucks', 2014, 2024, 'Rear'],
        ['Shocks & Struts', 'Fox', '2.0 Performance Series Shock', 169.00, false, 'jeep', 2012, 2024, 'Front'],
        ['Control Arms', 'Moog', 'Front Lower Control Arm with Ball Joint', 94.99, false, 'suvs', 2013, 2022, 'Front'],
        ['Control Arms', 'Mevotech', 'Supreme Front Upper Control Arm', 88.00, true, 'trucks', 2014, 2023, 'Front'],
        ['Control Arms', 'Lemforder', 'Front Thrust Control Arm', 142.50, false, 'euro', 2012, 2021, 'Front'],
        ['Ball Joints', 'Moog', 'Problem Solver Lower Ball Joint', 44.99, true, 'heavy', 2011, 2022, 'Front'],
        ['Ball Joints', 'Delphi', 'Front Lower Ball Joint', 38.00, false, 'sedans', 2012, 2022, 'Front'],
        ['Ball Joints', 'Dana Spicer', 'Upper and Lower Ball Joint Kit', 164.00, false, 'jeep', 2012, 2023, 'Front'],

        ['Batteries', 'ACDelco', 'Gold AGM Battery Group 48', 209.99, false, 'euro', 2013, 2024, null],
        ['Batteries', 'Optima', 'RedTop Starting Battery Group 34', 239.00, true, 'muscle', 2012, 2023, null],
        ['Batteries', 'Interstate', 'Heavy Duty Truck Battery Group 65', 194.00, false, 'heavy', 2011, 2024, null],
        ['Alternators', 'Denso', 'Remanufactured Alternator 130A', 168.00, false, 'sedans', 2012, 2020, null],
        ['Alternators', 'Bosch', 'Remanufactured Alternator 150A', 214.00, true, 'suvs', 2013, 2021, null],
        ['Alternators', 'Motorcraft', 'Remanufactured Alternator 200A', 289.00, false, 'trucks', 2012, 2020, null],
        ['Starters', 'Bosch', 'Remanufactured Starter Motor', 132.50, true, 'sedans', 2012, 2021, null],
        ['Starters', 'Denso', 'New Starter Motor', 196.00, false, 'suvs', 2013, 2022, null],
        ['Starters', 'ACDelco', 'Heavy Duty Remanufactured Starter', 178.00, false, 'heavy', 2011, 2021, null],
        ['Sensors', 'Denso', 'Downstream Oxygen Sensor', 61.99, false, 'sedans', 2012, 2023, null],
        ['Sensors', 'Bosch', 'Mass Air Flow Sensor', 98.00, true, 'euro', 2012, 2022, null],
        ['Sensors', 'Standard', 'Front Wheel ABS Speed Sensor', 34.50, false, 'trucks', 2014, 2024, 'Front'],

        ['Radiators', 'Denso', 'OE-Replacement Aluminum Radiator', 162.00, false, 'sedans', 2012, 2022, null],
        ['Radiators', 'Mishimoto', 'Performance Aluminum Radiator', 349.00, false, 'muscle', 2015, 2023, null],
        ['Radiators', 'Spectra', 'Complete Truck Radiator', 228.00, true, 'trucks', 2014, 2022, null],
        ['Water Pumps', 'Gates', 'Engine Water Pump', 57.99, true, 'suvs', 2012, 2022, null],
        ['Water Pumps', 'Aisin', 'OE-Quality Water Pump', 82.00, false, 'sedans', 2012, 2021, null],
        ['Water Pumps', 'Hepu', 'Electric Water Pump', 244.00, false, 'euro', 2013, 2021, null],
        ['Thermostats', 'Motorcraft', 'Engine Coolant Thermostat', 18.99, false, 'trucks', 2012, 2023, null],
        ['Thermostats', 'Stant', 'SuperStat Coolant Thermostat', 16.50, true, 'sedans', 2012, 2022, null],
        ['Thermostats', 'Behr', 'Thermostat Housing Assembly', 68.00, false, 'euro', 2012, 2021, null],

        ['Headlights', 'Depo', 'Halogen Headlight Assembly', 124.00, false, 'sedans', 2014, 2022, 'Front Left'],
        ['Headlights', 'Hella', 'LED Projector Headlight Pair', 449.00, false, 'jeep', 2014, 2024, null],
        ['Headlights', 'TYC', 'Replacement Headlight Assembly', 139.00, true, 'trucks', 2015, 2022, 'Front Right'],
        ['Tail Lights', 'TYC', 'Replacement Tail Light Assembly', 94.00, true, 'suvs', 2014, 2022, null],
        ['Tail Lights', 'Morimoto', 'XB LED Smoked Tail Lights', 599.00, false, 'trucks', 2015, 2023, null],
        ['Tail Lights', 'Depo', 'Rear Tail Lamp Assembly', 79.50, false, 'sedans', 2013, 2021, null],
        ['LED & Accent Lights', 'Philips', 'Ultinon LED Bulb Pair', 69.99, true, 'sedans', 2012, 2025, null],
        ['LED & Accent Lights', 'Rigid', '20-Inch LED Light Bar', 379.00, false, 'jeep', 2012, 2025, null],
        ['LED & Accent Lights', 'Sylvania', 'ZEVO LED Interior Light Kit', 29.99, false, 'suvs', 2013, 2025, null],

        ['Mirrors', 'Dorman', 'Power Heated Side Mirror', 86.00, false, 'suvs', 2013, 2022, 'Front Left'],
        ['Mirrors', 'K Source', 'Towing Mirror Pair', 219.00, true, 'heavy', 2012, 2022, null],
        ['Mirrors', 'TYC', 'Manual Folding Side Mirror', 62.00, false, 'sedans', 2012, 2021, 'Front Right'],
        ['Spoilers & Wings', 'Duraflex', 'Fiberglass Rear Deck Spoiler', 189.00, false, 'muscle', 2015, 2023, 'Rear'],
        ['Spoilers & Wings', 'Anderson', 'Carbon Fiber Rear Wing', 899.00, false, 'muscle', 2016, 2023, 'Rear'],
        ['Spoilers & Wings', 'AeroFlow', 'ABS Trunk Lip Spoiler', 119.00, true, 'euro', 2014, 2022, 'Rear'],
        ['Bumpers & Grilles', 'Smittybilt', 'XRC Steel Front Bumper', 549.00, false, 'jeep', 2012, 2024, 'Front'],
        ['Bumpers & Grilles', 'Replace', 'Primed Front Bumper Cover', 139.00, true, 'sedans', 2014, 2021, 'Front'],
        ['Bumpers & Grilles', 'Putco', 'Stainless Mesh Grille Insert', 229.00, false, 'trucks', 2015, 2023, 'Front'],

        ['Other Parts', 'WeatherTech', 'Custom-Fit Floor Liner Set', 149.95, false, 'suvs', 2015, 2025, null],
        ['Other Parts', 'Bosch', 'ICON Wiper Blade Pair', 49.99, true, 'sedans', 2012, 2025, null],
        ['Other Parts', 'BAK', 'Hard Folding Tonneau Cover', 989.00, false, 'trucks', 2015, 2025, null],
    ];

    public function run(): void
    {
        $this->call([CategorySeeder::class, SellerSeeder::class]);
        if (VehicleMake::where('slug', 'volkswagen')->doesntExist()) {
            $this->call(VehicleMakeSeeder::class);
        }

        $sellers = User::whereIn('email', SellerSeeder::EMAILS)->orderBy('email')->get();
        $attributes = $this->attributes();

        Product::withoutSyncingToSearch(function () use ($sellers, $attributes) {
            foreach (self::PARTS as $i => $part) {
                $this->part($i, $part, $sellers[$i % $sellers->count()], $attributes);
            }
        });

        $this->command?->info(count(self::PARTS).' sample products ready.');
    }

    private function part(int $i, array $part, User $seller, array $attributes): void
    {
        [$categoryName, $brandName, $title, $price, $onSale, $fits, $from, $to, $position] = $part;

        $category = Category::where('name', $categoryName)->first()
            ?? throw new RuntimeException("Unknown category: {$categoryName}");
        $brand = Brand::firstOrCreate(['slug' => Str::slug($brandName)], ['name' => $brandName]);
        $fullTitle = "{$brandName} {$title}";
        $partNumber = strtoupper(Str::substr(Str::slug($brandName, ''), 0, 3)).'-'.(crc32($fullTitle) % 90000 + 10000);

        $product = Product::firstOrCreate(
            ['seller_id' => $seller->id, 'slug' => Str::slug($fullTitle)],
            [
                'category_id' => $category->id,
                'brand_id' => $brand->id,
                'title' => $fullTitle,
                'sku' => $partNumber,
                'part_number' => $partNumber,
                'description' => "<p>{$title} from {$brandName}, made to fit {$this->fitLabel($fits)} from {$from} to {$to}. Check the fitment table to confirm your exact vehicle before ordering.</p>",
                'bullet_points' => ["Fits {$this->fitLabel($fits)}, {$from}-{$to}", "{$brandName} {$categoryName}", 'Ships from the US', '30-day returns'],
                'price' => $price,
                'sale_price' => $onSale ? round($price * 0.85, 2) : null,
                'cost_price' => round($price * 0.55, 2),
                'quantity' => [12, 25, 40, 60, 8, 18][$i % 6],
                'weight_lbs' => round(1 + ($price / 12), 1),
                'condition' => str_contains($title, 'Remanufactured') ? 'refurbished' : 'new',
                'status' => 'active',
                'published_at' => now()->subDays($i % 40),
            ]
        );

        if (! $product->wasRecentlyCreated) {
            return;
        }

        $product->fitments()->attach(
            $this->vehicleIds($fits, $from, $to)->mapWithKeys(fn ($id) => [$id => ['position' => $position]])->all()
        );

        $product->attributeValues()->attach(array_filter([
            $attributes['Warranty'][['1 Year', '2 Year', 'Lifetime'][$i % 3]],
            $position ? $attributes['Position'][$position] : null,
            str_contains($title, 'Ceramic') ? $attributes['Material']['Ceramic'] : null,
            str_contains($title, 'Semi-Metallic') ? $attributes['Material']['Semi-Metallic'] : null,
        ]));

        try {
            $product->addMediaFromString($this->placeholderPng($fullTitle, $categoryName))
                ->usingFileName($product->slug.'.png')
                ->toMediaCollection('images');
        } catch (\Throwable $e) {
            // conversions may fail without an image driver — original still stored
        }
    }

    private function vehicleIds(string $fits, int $from, int $to)
    {
        $modelIds = collect(self::FITS[$fits])->flatMap(fn ($models, $makeName) => collect($models)->map(
            fn ($modelName) => VehicleModel::where('name', $modelName)
                ->whereHas('make', fn ($q) => $q->where('name', $makeName))->value('id')
                ?? throw new RuntimeException("Unknown vehicle: {$makeName} {$modelName}")
        ));

        return Vehicle::whereIn('vehicle_model_id', $modelIds)->whereBetween('year', [$from, $to])->pluck('id');
    }

    private function fitLabel(string $fits): string
    {
        return collect(self::FITS[$fits])->flatMap(fn ($models, $make) => collect($models)->map(fn ($m) => "{$make} {$m}"))->join(', ', ' and ');
    }

    /** @return array<string, array<string, int>> attribute name => value => id */
    private function attributes(): array
    {
        $defs = [
            'Position' => ['Front', 'Rear', 'Front Left', 'Front Right'],
            'Material' => ['Ceramic', 'Semi-Metallic', 'Organic'],
            'Warranty' => ['1 Year', '2 Year', 'Lifetime'],
        ];

        $out = [];
        foreach ($defs as $name => $values) {
            $attribute = Attribute::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name]);
            foreach ($values as $value) {
                $out[$name][$value] = AttributeValue::firstOrCreate(['attribute_id' => $attribute->id, 'value' => $value])->id;
            }
        }

        return $out;
    }

    private function placeholderPng(string $title, string $category): string
    {
        $w = 800;
        $h = 800;
        $im = imagecreatetruecolor($w, $h);
        [$r, $g, $b] = [[21, 25, 30], [34, 40, 48], [21, 25, 30], [34, 40, 48]][crc32($category) % 4];
        imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
        $red = imagecolorallocate($im, 229, 27, 43);
        $white = imagecolorallocate($im, 255, 255, 255);

        imagesetthickness($im, 8);
        imagearc($im, $w / 2, $h / 2 - 40, 320, 320, 0, 360, $red);
        imagefilledellipse($im, $w / 2, $h / 2 - 40, 90, 90, $red);

        $label = strtoupper(substr($category, 0, 18));
        imagestring($im, 5, (int) (($w - strlen($label) * 9) / 2), $h - 120, $label, $white);
        $sub = substr($title, 0, 28);
        imagestring($im, 3, (int) (($w - strlen($sub) * 7) / 2), $h - 90, $sub, $red);

        ob_start();
        imagepng($im);
        $data = ob_get_clean();
        imagedestroy($im);

        return $data;
    }
}
