<?php

namespace Tests\Feature\Admin;

use App\Livewire\Seller\OnboardingWizard;
use App\Models\SellerProfile;
use App\Models\SellerTeamMember;
use App\Models\User;
use App\Notifications\AccountNotice;
use App\Services\Seller\SellerSignupService;
use Database\Seeders\AdminPermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
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

    private function admin(): User
    {
        $a = $this->withTwoFactor(User::factory()->create(['name' => 'Admin Person', 'email' => 'boss@example.com']));
        $a->assignRole('super_admin');

        return $a;
    }

    /** Someone who opened seller sign-up (the wizard) and stopped there. */
    private function startedSeller(array $attrs = []): User
    {
        $u = User::factory()->create($attrs + ['email' => 'new-seller-'.uniqid().'@example.com']);
        Livewire::actingAs($u)->test(OnboardingWizard::class);

        return $u->fresh();
    }

    public function test_starting_seller_sign_up_creates_a_pending_application_admin_can_approve(): void
    {
        $new = $this->startedSeller(['name' => 'Fahad Minhaj']);

        $profile = $new->sellerProfile;
        $this->assertNotNull($profile);
        $this->assertSame('pending', $profile->status);
        $this->assertSame(0, (int) $profile->setup_step);

        $this->actingAs($this->admin())->get(route('admin.sellers.index', ['status' => 'pending']))->assertOk()
            ->assertSee('Fahad Minhaj')->assertSee($new->email)->assertSee('0/3')->assertSee('Approve');
    }

    public function test_the_wizard_still_starts_at_business_details_and_the_store_takes_the_real_name(): void
    {
        $new = $this->startedSeller(['name' => 'Fahad Minhaj']);

        Livewire::actingAs($new)->test(OnboardingWizard::class)
            ->assertSet('step', 1)->assertSet('business_name', '')
            ->set('business_name', 'Minhaj Motorsports')->set('business_type', 'llc')->call('saveBusinessInfo')
            ->assertSet('step', 2);

        $p = $new->fresh()->sellerProfile;
        $this->assertSame('Minhaj Motorsports', $p->business_name);
        $this->assertSame(1, (int) $p->setup_step);
        $this->assertStringStartsWith('minhaj-motorsports', $p->slug);
        $this->assertSame(1, SellerProfile::where('user_id', $new->id)->count());   // never a second store

        Livewire::actingAs($new->fresh())->test(OnboardingWizard::class)->assertSet('step', 2);   // resumes
    }

    public function test_team_members_never_get_their_own_store(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        SellerTeamMember::create(['owner_id' => $owner->id, 'user_id' => $member->id, 'email' => $member->email, 'role' => 'staff', 'abilities' => [], 'status' => 'active']);

        Livewire::actingAs($member)->test(OnboardingWizard::class);
        $this->assertNull($member->fresh()->sellerProfile);
    }

    public function test_reminders_admin_button_and_automatic_once(): void
    {
        $new = $this->startedSeller();
        $this->actingAs($this->admin())->post(route('admin.sellers.remind-signup', $new))->assertRedirect();
        Notification::assertSentTo($new, AccountNotice::class, fn ($n) => str_contains($n->subject, 'Finish setting up'));

        $yesterday = $this->startedSeller();
        $yesterday->forceFill(['created_at' => now()->subHours(30)])->save();
        $justNow = $this->startedSeller();

        $this->artisan('sellers:remind-unfinished')->assertSuccessful();
        $this->artisan('sellers:remind-unfinished')->assertSuccessful();   // never twice

        Notification::assertSentToTimes($yesterday, AccountNotice::class, 1);
        Notification::assertNotSentTo($justNow, AccountNotice::class);
        $this->assertSame(3, app(SellerSignupService::class)->unfinished()->count());
    }

    public function test_buyers_and_accounts_lists_only_shoppers(): void
    {
        $buyer = User::factory()->create(['name' => 'Plain Shopper', 'email' => 'shopper@example.com']);
        $seller = $this->startedSeller(['name' => 'Store Owner', 'email' => 'owner@example.com']);
        $member = User::factory()->create(['email' => 'teammate@example.com']);
        SellerTeamMember::create(['owner_id' => $seller->id, 'user_id' => $member->id, 'email' => $member->email, 'role' => 'staff', 'abilities' => [], 'status' => 'active']);

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk()
            ->assertSee('shopper@example.com')->assertSee('All buyers')
            ->assertDontSee('owner@example.com')->assertDontSee('teammate@example.com')->assertDontSee('boss@example.com');
        $this->actingAs($admin)->get(route('admin.users.show', $seller))->assertOk();   // still openable from Sellers
    }
}
