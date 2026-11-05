<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Models\AdminAlert;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PayoutAdvance;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\Orders\BuyerRefundService;
use App\Services\Payments\InstantPayoutService;
use App\Services\Payments\PayoutReversalService;
use App\Services\Payments\RefundService;
use App\Services\Payments\TransferPayoutService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Stripe\Exception\ApiConnectionException;
use Stripe\Transfer;
use Tests\TestCase;

class InstantPayoutSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seller(): User
    {
        $u = User::factory()->create();
        $u->assignRole('seller');
        SellerProfile::create([
            'user_id' => $u->id, 'business_name' => 'Acme '.uniqid(), 'business_type' => 'llc', 'status' => 'approved',
            'stripe_account_id' => 'acct_test', 'stripe_onboarding_complete' => true,
        ]);

        return $this->withTwoFactor($u);
    }

    private function shippedItem(User $seller): OrderItem
    {
        $order = Order::create([
            'order_number' => 'PM-'.strtoupper(uniqid()), 'buyer_id' => User::factory()->create()->id,
            'subtotal' => 200, 'total' => 200, 'stripe_payment_intent_id' => 'pi_1',
            'shipping_address' => ['line1' => 'x'], 'billing_address' => ['line1' => 'x'],
            'status' => OrderStatus::Paid,
        ]);

        return $order->items()->create([
            'seller_id' => $seller->id,
            'product_id' => Product::create([
                'seller_id' => $seller->id,
                'category_id' => Category::create(['name' => 'C'.uniqid(), 'slug' => 'c-'.uniqid()])->id,
                'title' => 'Part', 'slug' => 'p-'.uniqid(), 'price' => 200, 'quantity' => 3, 'status' => 'active',
            ])->id,
            'product_title' => 'Part', 'quantity' => 1, 'unit_price' => 200, 'line_total' => 200,
            'commission_rate' => 10, 'platform_fee_amount' => 20, 'seller_payout_amount' => 180,
            'status' => OrderStatus::Shipped, 'shipped_at' => now(),
        ]);
    }

    public function test_only_the_net_is_sent_so_the_fee_is_really_kept(): void
    {
        $this->mock(TransferPayoutService::class)->shouldReceive('advanceToSeller')->once()
            ->withArgs(fn ($item, $account, $net) => $account === 'acct_test' && abs($net - 177.30) < 0.001)
            ->andReturn(Transfer::constructFrom(['id' => 'tr_real']));

        $item = $this->shippedItem($this->seller());
        $advance = app(InstantPayoutService::class)->advance($item);

        $this->assertSame('tr_real', $advance->stripe_transfer_id);
        $this->assertEquals(2.70, (float) $advance->fee);
    }

    public function test_when_stripe_fails_nothing_is_recorded_and_the_seller_is_told(): void
    {
        $this->mock(TransferPayoutService::class)->shouldReceive('advanceToSeller')->once()
            ->andThrow(new ApiConnectionException('Stripe is down'));

        $seller = $this->seller();
        $item = $this->shippedItem($seller);

        $this->actingAs($seller)->from(route('seller.orders.show', $item))
            ->post(route('seller.orders.instant-payout', $item))
            ->assertRedirect(route('seller.orders.show', $item))
            ->assertSessionHasErrors('advance');

        $this->assertSame(0, PayoutAdvance::count());
        $this->assertTrue(app(InstantPayoutService::class)->quote($item->fresh())['eligible'], 'The seller can try again.');

        $this->actingAs($seller)->withSession(['errors' => (new \Illuminate\Support\ViewErrorBag)->put('default', new \Illuminate\Support\MessageBag(['advance' => 'Stripe couldn\'t send this payout']))])
            ->get(route('seller.orders.show', $item))
            ->assertSee('Stripe couldn', false);
    }

    public function test_a_failed_advance_no_longer_blocks_refunds(): void
    {
        $this->mock(TransferPayoutService::class)->shouldReceive('advanceToSeller')->andThrow(new ApiConnectionException('down'));
        $this->mock(RefundService::class)->shouldReceive('refundAmount')->once();

        $item = $this->shippedItem($this->seller());
        try {
            app(InstantPayoutService::class)->advance($item);
        } catch (ValidationException) {
        }

        $this->assertEquals(200.0, app(BuyerRefundService::class)->refundLine($item->fresh(), 'test', 'Refund'));
    }

    public function test_unverified_sellers_cannot_use_instant_payout_to_skip_verification(): void
    {
        config()->set('marketplace.kyc.enforce_payouts', true);

        $quote = app(InstantPayoutService::class)->quote($this->shippedItem($this->seller()));

        $this->assertFalse($quote['eligible']);
        $this->assertStringContainsString('verification', $quote['reason']);
    }

    public function test_refund_after_an_advance_only_pulls_back_what_was_sent(): void
    {
        $seller = $this->seller();
        $item = $this->shippedItem($seller);

        $reversed = null;
        $transfers = new class($item, $reversed) {
            public function __construct(private OrderItem $item, public mixed &$reversed) {}

            public function all($params)
            {
                return (object) ['data' => [Transfer::constructFrom([
                    'id' => 'tr_1', 'amount' => 17730, 'amount_reversed' => 0,
                    'metadata' => ['order_item_id' => (string) $this->item->id],
                ])]];
            }

            public function createReversal($id, $params)
            {
                $this->reversed = $params['amount'];
            }
        };

        $service = new PayoutReversalService;
        $property = new \ReflectionProperty(PayoutReversalService::class, 'stripe');
        $property->setValue($service, new class($transfers) extends \Stripe\StripeClient {
            public function __construct(public object $fake)
            {
                parent::__construct('sk_test_x');
            }

            public function __get($name)
            {
                return $name === 'transfers' ? $this->fake : parent::__get($name);
            }
        });

        $service->reverseTransfer($item);

        $this->assertSame(17730, $reversed, 'Stripe refuses to reverse more than the $177.30 it sent.');
    }

    public function test_cleanup_removes_fake_advances_but_leaves_real_ones_and_keeps_a_record(): void
    {
        $seller = $this->seller();
        $fake = $this->shippedItem($seller);
        $real = $this->shippedItem($seller);
        $settled = $this->shippedItem($seller);

        $attrs = ['seller_id' => $seller->id, 'gross' => 180, 'fee' => 2.70, 'net_paid' => 177.30];
        PayoutAdvance::create($attrs + ['order_item_id' => $fake->id, 'stripe_transfer_id' => null, 'status' => 'advanced']);
        PayoutAdvance::create($attrs + ['order_item_id' => $real->id, 'stripe_transfer_id' => 'tr_ok', 'status' => 'advanced']);
        PayoutAdvance::create($attrs + ['order_item_id' => $settled->id, 'stripe_transfer_id' => null, 'status' => 'recouped']);

        $this->assertTrue(Schema::hasTable('payout_advances'));
        (require database_path('migrations/2026_11_05_100000_clear_instant_payouts_that_never_sent_money.php'))->up();

        $this->assertFalse(PayoutAdvance::where('order_item_id', $fake->id)->exists());
        $this->assertTrue(PayoutAdvance::where('order_item_id', $real->id)->exists());
        $this->assertTrue(PayoutAdvance::where('order_item_id', $settled->id)->exists(), 'Already settled: left for a person to check.');

        $this->assertSame(1, AdminAlert::where('type', 'instant_payout_removed')->where('related_order_item_id', $fake->id)->count());
        $this->assertSame(1, AdminAlert::where('type', 'instant_payout_check')->where('related_order_item_id', $settled->id)->count());
        $this->assertSame(0, AdminAlert::where('related_order_item_id', $real->id)->count());
    }
}
