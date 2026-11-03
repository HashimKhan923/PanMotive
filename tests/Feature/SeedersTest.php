<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMake;
use App\Models\VehicleModel;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SeedersTest extends TestCase
{
    use RefreshDatabase;

    private function run_(string $seeder): void
    {
        (new $seeder)->setContainer(app())->run();
    }

    public function test_one_seed_command_adds_everything_and_works_twice(): void
    {
        Storage::fake('public');
        Queue::fake();
        Notification::fake();

        $this->run_(DatabaseSeeder::class);

        $counts = fn () => [Category::count(), VehicleMake::count(), Vehicle::count(), User::count(), Product::count(), Order::count()];
        $first = $counts();
        $this->run_(DatabaseSeeder::class);
        $this->assertSame($first, $counts());

        $this->assertSame(75, Product::count());
        $this->assertSame(12, Order::count());
        $this->assertSame(3, User::role('seller')->count());
        $this->assertDatabaseHas('users', ['email' => 'buyer6@panmotive.test']);

        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
        $admin = User::where('email', 'admin@panmotive.test')->firstOrFail();
        $this->assertTrue($admin->hasRole('super_admin'));
        $this->assertFalse(Hash::check('password', $admin->password));

        foreach (['Cooling', 'Lighting', 'Other Parts', 'Ball Joints', 'Mirrors'] as $name) {
            $this->assertDatabaseHas('categories', ['name' => $name]);
        }

        // Only the years the model was on sale.
        $years = Vehicle::whereHas('model', fn ($q) => $q->where('name', 'Ridgeline'))->pluck('year');
        $this->assertTrue($years->contains(2014));
        $this->assertFalse($years->contains(2015));
        $this->assertTrue($years->contains(2017));
    }

    public function test_demo_seed_on_a_production_server_is_browsable_and_has_random_passwords(): void
    {
        Storage::fake('public');
        Queue::fake();
        Notification::fake();
        app()->detectEnvironment(fn () => 'production');

        $this->run_(DemoSeeder::class);

        $this->assertSame(75, Product::count());
        $this->assertGreaterThan(0, Product::listed()->count());
        $this->assertSame(12, Order::count());
        $this->assertTrue(Product::whereHas('fitments')->exists());

        $this->assertTrue(User::where('email', 'seller1@panmotive.test')->firstOrFail()->hasRole('seller'));
        foreach (['seller1@panmotive.test', 'buyer1@panmotive.test'] as $email) {
            $this->assertFalse(Hash::check('password', User::where('email', $email)->firstOrFail()->password));
        }
    }

    public function test_products_cover_every_subcategory_and_fit_the_right_vehicles(): void
    {
        Storage::fake('public');
        Queue::fake();
        Notification::fake();

        $this->run_(ProductSeeder::class);
        $products = Product::count();
        $this->run_(ProductSeeder::class);
        $this->assertSame($products, Product::count());

        Category::whereDoesntHave('children')->each(
            fn ($category) => $this->assertGreaterThanOrEqual(3, $category->products()->count(), $category->name)
        );
        $this->assertSame(0, Product::whereDoesntHave('fitments')->count());
        $this->assertSame(Product::count(), Product::listed()->count());

        $fits = fn (string $model, int $year) => Product::whereHas('fitments', fn ($f) => $f
            ->where('year', $year)->whereHas('model', fn ($m) => $m->where('name', $model)))->pluck('title');

        // A sedan and a truck get their own parts, and only inside each listing's years.
        $this->assertTrue($fits('Camry', 2018)->contains('Bosch QuietCast Ceramic Front Brake Pad Set'));
        $this->assertFalse($fits('Camry', 2018)->contains('Power Stop Z36 Truck & Tow Carbon-Ceramic Brake Pads'));
        $this->assertTrue($fits('F-150', 2018)->contains('Power Stop Z36 Truck & Tow Carbon-Ceramic Brake Pads'));
        $this->assertFalse($fits('Camry', 2013)->contains('Bosch QuietCast Ceramic Front Brake Pad Set'));

        // Every model used has parts in more than one category.
        $camry = VehicleModel::where('name', 'Camry')->first();
        $categories = Product::whereHas('fitments', fn ($f) => $f->where('vehicle_model_id', $camry->id))->distinct()->count('category_id');
        $this->assertGreaterThan(5, $categories);
    }
}
