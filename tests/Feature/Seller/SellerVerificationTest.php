<?php

namespace Tests\Feature\Seller;

use App\Livewire\Seller\Verification;
use App\Models\SellerProfile;
use App\Models\SellerVerification;
use App\Models\User;
use Database\Seeders\AdminPermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SellerVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(AdminPermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seller(): User
    {
        $u = User::factory()->create();
        $u->assignRole('seller');
        SellerProfile::create(['user_id' => $u->id, 'business_name' => 'KYC '.uniqid(), 'business_type' => 'llc', 'status' => 'approved', 'slug' => 'kyc-'.uniqid()]);

        return $this->withTwoFactor($u);
    }

    public function test_seller_submits_verification_with_documents(): void
    {
        Storage::fake('local');
        $seller = $this->seller();

        Livewire::actingAs($seller)->test(Verification::class)
            ->set('legalName', 'Acme Auto Parts LLC')
            ->set('registeredAddress', '100 Main St, Springfield, IL')
            ->set('taxIdType', 'ein')
            ->set('taxIdLast4', '4821')
            ->set('businessDoc', UploadedFile::fake()->create('ein.pdf', 200, 'application/pdf'))
            ->set('idDoc', UploadedFile::fake()->image('license.jpg'))
            ->call('submit')
            ->assertHasNoErrors();

        $v = SellerVerification::where('seller_id', $seller->id)->firstOrFail();
        $this->assertSame('pending', $v->status);
        $this->assertSame('4821', $v->tax_id_last4);
        $this->assertNotNull($v->business_doc_path);
        Storage::disk('local')->assertExists($v->business_doc_path);
        $this->assertDatabaseHas('admin_alerts', ['type' => 'kyc_submitted']);
    }

    public function test_missing_documents_block_submission(): void
    {
        Storage::fake('local');
        $seller = $this->seller();

        Livewire::actingAs($seller)->test(Verification::class)
            ->set('legalName', 'X')->set('registeredAddress', 'Y')
            ->set('taxIdType', 'ein')->set('taxIdLast4', '1234')
            ->call('submit')
            ->assertHasErrors(['businessDoc', 'idDoc']);
    }

    public function test_admin_approves_verification_and_sets_kyc_status(): void
    {
        $seller = $this->seller();
        $v = SellerVerification::create([
            'seller_id' => $seller->id, 'legal_name' => 'Acme', 'status' => 'pending',
            'tax_id_type' => 'ein', 'tax_id_last4' => '0000',
            'business_doc_path' => 'x.pdf', 'id_doc_path' => 'y.jpg', 'submitted_at' => now(),
        ]);
        $admin = $this->withTwoFactor(tap(User::factory()->create())->assignRole('super_admin'));

        $this->actingAs($admin)->post(route('admin.verifications.review', $v), ['decision' => 'approve'])
            ->assertRedirect();

        $this->assertSame('approved', $v->fresh()->status);
        $this->assertSame('verified', $seller->sellerProfile->fresh()->kyc_status);
    }

    public function test_admin_rejects_with_reason(): void
    {
        $seller = $this->seller();
        $v = SellerVerification::create(['seller_id' => $seller->id, 'status' => 'pending', 'submitted_at' => now()]);
        $admin = $this->withTwoFactor(tap(User::factory()->create())->assignRole('super_admin'));

        $this->actingAs($admin)->post(route('admin.verifications.review', $v), ['decision' => 'reject', 'reason' => 'ID photo unreadable'])
            ->assertRedirect();

        $this->assertSame('rejected', $v->fresh()->status);
        $this->assertSame('ID photo unreadable', $v->fresh()->rejection_reason);
        $this->assertSame('rejected', $seller->sellerProfile->fresh()->kyc_status);
    }
}
