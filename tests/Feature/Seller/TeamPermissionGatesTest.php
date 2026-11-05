<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Livewire\Seller\Offers;
use App\Livewire\Seller\ProductList;
use App\Models\Category;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\SellerTeamMember;
use App\Models\User;
use App\Support\SellerContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TeamPermissionGatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        SellerContext::clear();
        parent::tearDown();
    }

    private function owner(): User
    {
        $u = User::factory()->create();
        $u->assignRole('seller');
        SellerProfile::create(['user_id' => $u->id, 'business_name' => 'Owner '.uniqid(), 'business_type' => 'llc', 'status' => 'approved', 'slug' => 'o-'.uniqid()]);

        return $this->withTwoFactor($u);
    }

    /** @return array{0:User,1:SellerTeamMember} */
    private function member(User $owner, array $abilities): array
    {
        $u = $this->withTwoFactor(User::factory()->create(['email' => Str::random(8).'@example.com']));

        return [$u, SellerTeamMember::create([
            'owner_id' => $owner->id, 'user_id' => $u->id, 'email' => $u->email, 'role' => 'custom',
            'abilities' => $abilities, 'status' => 'active', 'accepted_at' => now(),
        ])];
    }

    private function product(User $owner): Product
    {
        return Product::create([
            'seller_id' => $owner->id,
            'category_id' => Category::create(['name' => 'C'.uniqid(), 'slug' => 'c-'.uniqid()])->id,
            'title' => 'Rotor '.uniqid(), 'slug' => 'p-'.uniqid(), 'price' => 50, 'quantity' => 5, 'status' => 'active',
        ]);
    }

    private function orderItem(User $owner): OrderItem
    {
        $order = Order::create([
            'order_number' => 'PM-'.strtoupper(uniqid()), 'buyer_id' => User::factory()->create()->id,
            'subtotal' => 50, 'total' => 50, 'shipping_address' => ['line1' => 'x'], 'billing_address' => ['line1' => 'x'],
            'status' => OrderStatus::Paid,
        ]);

        return $order->items()->create([
            'seller_id' => $owner->id, 'product_id' => $this->product($owner)->id, 'product_title' => 'Rotor',
            'quantity' => 1, 'unit_price' => 50, 'line_total' => 50, 'commission_rate' => 10,
            'platform_fee_amount' => 5, 'seller_payout_amount' => 45, 'status' => OrderStatus::Paid,
        ]);
    }

    private function offer(User $owner): Offer
    {
        $product = $this->product($owner);

        return Offer::create([
            'seller_id' => $owner->id, 'buyer_id' => User::factory()->create()->id, 'product_id' => $product->id,
            'offer_price' => 40, 'quantity' => 1, 'status' => 'pending', 'expires_at' => now()->addDay(),
        ]);
    }

    public function test_pages_are_closed_to_members_without_the_permission(): void
    {
        $owner = $this->owner();
        $item = $this->orderItem($owner);
        [$messagesOnly] = $this->member($owner, ['messages']);

        foreach ([
            route('seller.products.index'),
            route('seller.bundles.index'),
            route('seller.offers.index'),
            route('seller.orders.index'),
            route('seller.orders.export'),
            route('seller.orders.show', $item),
            route('seller.orders.invoice', $item->order_id),
            route('seller.returns.index'),
        ] as $url) {
            $this->actingAs($messagesOnly)->get($url)->assertForbidden();
        }
    }

    public function test_the_right_permission_opens_the_right_pages(): void
    {
        $owner = $this->owner();
        $item = $this->orderItem($owner);

        [$packer] = $this->member($owner, ['fulfilment']);
        $this->actingAs($packer)->get(route('seller.orders.show', $item))->assertOk();
        $this->actingAs($packer)->get(route('seller.orders.index'))->assertForbidden();

        [$returns] = $this->member($owner, ['returns.manage']);
        $this->actingAs($returns)->get(route('seller.returns.index'))->assertOk();
        $this->actingAs($returns)->get(route('seller.orders.show', $item))->assertOk();
        $this->actingAs($returns)->get(route('seller.offers.index'))->assertForbidden();

        [$catalog] = $this->member($owner, ['products.view', 'products.manage']);
        $this->actingAs($catalog)->get(route('seller.bundles.index'))->assertOk();
        $this->actingAs($catalog)->get(route('seller.products.index'))->assertOk()->assertSee('New product');
    }

    public function test_the_owner_keeps_every_page(): void
    {
        $owner = $this->owner();
        $item = $this->orderItem($owner);

        foreach ([route('seller.products.index'), route('seller.bundles.index'), route('seller.offers.index'), route('seller.orders.index'), route('seller.orders.show', $item), route('seller.returns.index')] as $url) {
            $this->actingAs($owner)->get($url)->assertOk();
        }
    }

    public function test_a_viewer_sees_products_but_gets_no_edit_buttons(): void
    {
        $owner = $this->owner();
        $product = $this->product($owner);
        [$viewer] = $this->member($owner, SellerTeamMember::presetAbilities('viewer'));

        $this->actingAs($viewer)->get(route('seller.products.index'))->assertOk()
            ->assertSee($product->title)
            ->assertDontSee('New product')
            ->assertDontSee('Bulk upload')
            ->assertDontSee('Click to edit')
            ->assertDontSee('wire:click="archive', false)
            ->assertDontSee('wire:click="delete', false)
            ->assertDontSee('wire:model.live="selected"', false);
    }

    public function test_a_viewer_cannot_change_a_product_by_calling_the_action_directly(): void
    {
        $owner = $this->owner();
        $product = $this->product($owner);
        [$viewer, $membership] = $this->member($owner, SellerTeamMember::presetAbilities('viewer'));
        SellerContext::set($owner, $viewer, $membership);

        foreach ([
            ['archive', [$product->id]], ['activate', [$product->id]], ['delete', [$product->id]], ['duplicate', [$product->id]],
            ['bulk', ['archive']], ['bulk', ['delete']], ['applyBulkPrice', []], ['startEdit', [$product->id]],
            ['saveEdit', []], ['saveAsTemplate', [$product->id]], ['deleteTemplate', [1]],
        ] as [$action, $args]) {
            Livewire::actingAs($owner)->test(ProductList::class)
                ->set('selected', [(string) $product->id])
                ->set('priceValue', 1)
                ->call($action, ...$args)
                ->assertForbidden();
        }

        $fresh = $product->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertEquals(50, (float) $fresh->price);
        $this->assertSame(1, Product::where('seller_id', $owner->id)->count());
    }

    public function test_a_viewer_sees_offers_but_cannot_answer_them(): void
    {
        $owner = $this->owner();
        $offer = $this->offer($owner);
        [$viewer, $membership] = $this->member($owner, SellerTeamMember::presetAbilities('viewer'));

        $this->actingAs($viewer)->get(route('seller.offers.index'))->assertOk()
            ->assertDontSee('wire:click="accept', false)
            ->assertDontSee('wire:click="decline', false);

        SellerContext::set($owner, $viewer, $membership);
        foreach (['accept', 'decline', 'sendCounter'] as $action) {
            Livewire::actingAs($owner)->test(Offers::class)
                ->set("counter.{$offer->id}", '45')
                ->call($action, $offer->id)
                ->assertForbidden();
        }

        $this->assertSame('pending', $offer->fresh()->status);
    }

    public function test_someone_who_can_manage_can_still_do_the_work(): void
    {
        $owner = $this->owner();
        $product = $this->product($owner);
        $offer = $this->offer($owner);
        [$staff, $membership] = $this->member($owner, SellerTeamMember::presetAbilities('staff'));
        SellerContext::set($owner, $staff, $membership);

        Livewire::actingAs($owner)->test(ProductList::class)->call('archive', $product->id)->assertOk();
        $this->assertSame('archived', $product->fresh()->status);

        Livewire::actingAs($owner)->test(Offers::class)->call('decline', $offer->id)->assertOk();
        $this->assertSame('declined', $offer->fresh()->status);
    }
}
