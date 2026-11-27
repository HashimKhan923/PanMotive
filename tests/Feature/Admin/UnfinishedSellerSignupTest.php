<?php

namespace Tests\Feature\Admin;

use App\Models\SellerProfile;
use App\Models\SellerTeamMember;
use App\Models\User;
use App\Notifications\AccountNotice;
use App\Services\Seller\SellerSignupService;
use Database\Seeders\AdminPermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UnfinishedSellerSignupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(AdminPermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Notification::fake();
    }

    private function startedSeller(array $attrs = []): User
    {
        $u = User::factory()->create($attrs + ['email' => 'new-seller-'.uniqid().'@example.com']);
        $u->assignRole('seller');

        return $u;
    }

    private function admin(): User
    {
        $a = $this->withTwoFactor(User::factory()->create());
        $a->assignRole('super_admin');

        return $a;
    }

    public function test_opening_seller_sign_up_without_finishing_shows_in_admin(): void
    {
        $new = $this->startedSeller(['name' => 'Half Way']);
        $done = $this->startedSeller();
        SellerProfile::create(['user_id' => $done->id, 'business_name' => 'Done Store', 'business_type' => 'llc', 'status' => 'pending', 'slug' => 'done-store']);
        $owner = $this->startedSeller();
        $member = $this->startedSeller();
        SellerTeamMember::create(['owner_id' => $owner->id, 'user_id' => $member->id, 'email' => $member->email, 'role' => 'staff', 'abilities' => [], 'status' => 'active']);

        $page = $this->actingAs($this->admin())->get(route('admin.sellers.index', ['status' => 'started']))->assertOk()
            ->assertSee('Started sign-up')->assertSee('Half Way')->assertSee($new->email)->assertDontSee($done->email)->assertDontSee($member->email);

        $this->actingAs($this->admin())->get(route('admin.sellers.index'))->assertOk()->assertSee('Done Store');
    }

    public function test_admin_can_send_a_reminder(): void
    {
        $new = $this->startedSeller();
        $this->actingAs($this->admin())->post(route('admin.sellers.remind-signup', $new))->assertRedirect();

        Notification::assertSentTo($new, AccountNotice::class, fn ($n) => str_contains($n->subject, 'Finish setting up'));
        $this->assertNotNull($new->fresh()->seller_signup_reminded_at);
    }

    public function test_automatic_reminder_goes_once_a_day_after_sign_up(): void
    {
        $yesterday = $this->startedSeller(['created_at' => now()->subHours(30)]);
        $justNow = $this->startedSeller(['created_at' => now()->subHour()]);
        $old = $this->startedSeller(['created_at' => now()->subDays(40)]);

        $this->artisan('sellers:remind-unfinished')->assertSuccessful();
        $this->artisan('sellers:remind-unfinished')->assertSuccessful();   // never twice

        Notification::assertSentToTimes($yesterday, AccountNotice::class, 1);
        Notification::assertNotSentTo($justNow, AccountNotice::class);
        Notification::assertNotSentTo($old, AccountNotice::class);
        $this->assertSame(3, app(SellerSignupService::class)->unfinished()->count());
    }
}
