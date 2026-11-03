<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMake;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
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

    public function test_base_seed_works_twice_and_leaves_no_known_login(): void
    {
        $this->run_(DatabaseSeeder::class);

        $counts = [Category::count(), VehicleMake::count(), Vehicle::count(), User::count()];
        $this->run_(DatabaseSeeder::class);
        $this->assertSame($counts, [Category::count(), VehicleMake::count(), Vehicle::count(), User::count()]);

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

        $this->assertSame(24, Product::count());
        $this->assertGreaterThan(0, Product::listed()->count());
        $this->assertSame(12, Order::count());
        $this->assertTrue(Product::whereHas('fitments')->exists());

        $this->assertTrue(User::where('email', 'seller1@panmotive.test')->firstOrFail()->hasRole('seller'));
        foreach (['seller1@panmotive.test', 'buyer1@panmotive.test'] as $email) {
            $this->assertFalse(Hash::check('password', User::where('email', $email)->firstOrFail()->password));
        }
    }
}
