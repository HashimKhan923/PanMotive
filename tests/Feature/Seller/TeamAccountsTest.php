<?php

namespace Tests\Feature\Seller;

use App\Livewire\Seller\Team;
use App\Models\Category;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\SellerTeamMember;
use App\Models\User;
use App\Notifications\SellerTeamInvite;
use App\Services\Seller\TeamService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TeamAccountsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function owner(): User
    {
        $u = User::factory()->create();
        $u->assignRole('seller');
        SellerProfile::create(['user_id' => $u->id, 'business_name' => 'Owner Co '.uniqid(), 'business_type' => 'llc', 'status' => 'approved', 'slug' => 'oc-'.uniqid()]);

        return $this->withTwoFactor($u);
    }

    private function product(User $owner): Product
    {
        return Product::create([
            'seller_id' => $owner->id,
            'category_id' => Category::create(['name' => 'C'.uniqid(), 'slug' => 'c-'.uniqid()])->id,
            'title' => 'P '.uniqid(), 'slug' => 'p-'.uniqid(),
            'price' => 50, 'quantity' => 5, 'status' => 'active',
        ]);
    }

    public function test_owner_invites_and_member_accepts(): void
    {
        Notification::fake();
        $owner = $this->owner();

        Livewire::actingAs($owner)->test(Team::class)
            ->set('inviteEmail', 'staffer@example.com')
            ->set('inviteRole', 'staff')
            ->call('invite')
            ->assertHasNoErrors();

        Notification::assertSentOnDemand(SellerTeamInvite::class);
        $member = SellerTeamMember::where('owner_id', $owner->id)->firstOrFail();
        $this->assertSame('invited', $member->status);
        $this->assertContains('orders.manage', $member->abilities);
        $this->assertNotContains('team.manage', $member->abilities);

        $staffer = User::factory()->create(['email' => 'staffer@example.com']);
        app(TeamService::class)->accept($member->invite_token, $staffer);

        $member->refresh();
        $this->assertSame('active', $member->status);
        $this->assertSame($staffer->id, $member->user_id);
        $this->assertNull($member->invite_token);
    }

    public function test_team_member_operates_the_owner_store(): void
    {
        $owner = $this->owner();
        $this->product($owner);
        $staffer = $this->withTwoFactor(User::factory()->create(['email' => 's@example.com']));
        SellerTeamMember::create([
            'owner_id' => $owner->id, 'user_id' => $staffer->id, 'email' => 's@example.com',
            'role' => 'staff', 'abilities' => SellerTeamMember::presetAbilities('staff'), 'status' => 'active',
        ]);

        // Acts as the owner store — sees the owner's product list.
        $this->actingAs($staffer)->get(route('seller.products.index'))
            ->assertOk()
            ->assertSee(Product::where('seller_id', $owner->id)->first()->title);
    }

    public function test_viewer_role_is_blocked_from_write_routes(): void
    {
        $owner = $this->owner();
        $product = $this->product($owner);
        $viewer = $this->withTwoFactor(User::factory()->create(['email' => 'v@example.com']));
        SellerTeamMember::create([
            'owner_id' => $owner->id, 'user_id' => $viewer->id, 'email' => 'v@example.com',
            'role' => 'viewer', 'abilities' => SellerTeamMember::presetAbilities('viewer'), 'status' => 'active',
        ]);

        $this->actingAs($viewer)->get(route('seller.products.index'))->assertOk();           // products.view — allowed
        $this->actingAs($viewer)->get(route('seller.products.create'))->assertForbidden(); // products.manage — blocked
        $this->actingAs($viewer)->get(route('seller.settings.edit'))->assertForbidden();       // settings.manage — blocked
        $this->actingAs($viewer)->get(route('seller.team.index'))->assertForbidden();           // team.manage — blocked
    }

    public function test_suspended_member_loses_portal_access(): void
    {
        $owner = $this->owner();
        $member = SellerTeamMember::create([
            'owner_id' => $owner->id, 'user_id' => ($u = $this->withTwoFactor(User::factory()->create(['email' => 'x@example.com'])))->id,
            'email' => 'x@example.com', 'role' => 'staff', 'abilities' => SellerTeamMember::presetAbilities('staff'), 'status' => 'active',
        ]);

        $this->actingAs($u)->get(route('seller.products.index'))->assertOk();

        app(TeamService::class)->setStatus($member, 'suspended');

        $this->actingAs($u)->get(route('seller.products.index'))->assertRedirect(route('seller.onboarding.start'));
    }

    public function test_owner_still_operates_own_store_normally(): void
    {
        $owner = $this->owner();
        $this->product($owner);

        $this->actingAs($owner)->get(route('seller.products.index'))->assertOk();
        $this->actingAs($owner)->get(route('seller.settings.edit'))->assertOk();
        $this->actingAs($owner)->get(route('seller.team.index'))->assertOk();
    }

    public function test_accept_rejects_wrong_email(): void
    {
        $owner = $this->owner();
        $member = SellerTeamMember::create([
            'owner_id' => $owner->id, 'email' => 'invited@example.com', 'role' => 'staff',
            'abilities' => SellerTeamMember::presetAbilities('staff'), 'status' => 'invited',
            'invite_token' => str_repeat('a', 40), 'invited_at' => now(),
        ]);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(TeamService::class)->accept($member->invite_token, User::factory()->create(['email' => 'someoneelse@example.com']));
    }
}
