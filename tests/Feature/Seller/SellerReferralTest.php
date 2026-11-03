<?php

namespace Tests\Feature\Seller;

use App\Livewire\Seller\OnboardingWizard;
use App\Models\SellerProfile;
use App\Models\SellerReferral;
use App\Models\User;
use App\Services\Seller\SellerReferralService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SellerReferralTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function approvedSeller(): User
    {
        $u = User::factory()->create();
        $u->assignRole('seller');
        SellerProfile::create(['user_id' => $u->id, 'business_name' => 'Ref '.uniqid(), 'business_type' => 'llc', 'status' => 'approved']);

        return $u;
    }

    public function test_referral_link_attach_and_activation_discounts_both_sides(): void
    {
        $service = app(SellerReferralService::class);
        $referrer = $this->approvedSeller();

        $link = $service->linkFor($referrer);
        $this->assertSame('pending', $link->status);

        // New seller onboards via the code.
        $newbie = User::factory()->create();
        Livewire::actingAs($newbie)->withUrlParams(['sref' => $link->code] ?? [])->test(OnboardingWizard::class)
            ->set('sref', $link->code)
            ->set('business_name', 'Fresh Parts')
            ->set('business_type', 'llc')
            ->call('saveBusinessInfo')
            ->assertSet('step', 2);

        $this->assertDatabaseHas('seller_referrals', [
            'referrer_id' => $referrer->id,
            'referred_id' => $newbie->id,
            'status' => 'pending',
        ]);

        // Approval activates the bonus for both.
        $service->activateFor($newbie);

        foreach ([$referrer, $newbie] as $u) {
            $p = $u->sellerProfile()->first();
            $this->assertNotNull($p->referral_bonus_pct);
            $this->assertTrue($p->referral_bonus_until->isFuture());
            $this->assertLessThan(10.0, $p->commissionRate()); // 10% default − 2% bonus
        }

        $this->assertSame('active', SellerReferral::where('referred_id', $newbie->id)->value('status'));
    }

    public function test_expired_bonus_is_swept(): void
    {
        $service = app(SellerReferralService::class);
        $seller = $this->approvedSeller();

        SellerReferral::create([
            'referrer_id' => $seller->id, 'referred_id' => User::factory()->create()->id,
            'code' => 'OLDCODE1', 'status' => 'active',
            'commission_discount_pct' => 2, 'activated_at' => now()->subMonths(4), 'ends_at' => now()->subDay(),
        ]);
        $seller->sellerProfile->update(['referral_bonus_pct' => 2, 'referral_bonus_until' => now()->subDay()]);

        $service->sweepExpired();

        $this->assertNull($seller->sellerProfile->fresh()->referral_bonus_pct);
        $this->assertSame(10.0, $seller->sellerProfile->fresh()->commissionRate());
    }

    public function test_cannot_refer_yourself(): void
    {
        $service = app(SellerReferralService::class);
        $seller = $this->approvedSeller();
        $link = $service->linkFor($seller);

        $this->assertNull($service->attach($link->code, $seller));
    }
}
