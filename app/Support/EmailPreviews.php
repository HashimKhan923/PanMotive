<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\SellerTeamMember;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Lets the admin see exactly what each email looks like. Sample people, a sample product and a
 * sample order are created inside a database transaction that is always rolled back, so a
 * preview never leaves anything behind. A real product photo is borrowed when one exists.
 */
class EmailPreviews
{
    /** @return array<string, array{label: string, group: string, when: string}> */
    public static function catalog(): array
    {
        return [
            'order_confirmation' => ['label' => 'Order confirmation (receipt)', 'group' => 'Buyers', 'when' => 'Right after an order is paid. Guests get it too.'],
            'order_shipped' => ['label' => 'Order shipped', 'group' => 'Buyers', 'when' => 'When the seller adds tracking, one per package.'],
            'order_delivered' => ['label' => 'Order delivered', 'group' => 'Buyers', 'when' => 'When the carrier scans the package as delivered.'],
            'item_cancelled' => ['label' => 'Item cancelled + refund', 'group' => 'Buyers', 'when' => 'When a seller cancels an item.'],
            'payment_reminder' => ['label' => 'Finish paying reminder', 'group' => 'Buyers', 'when' => 'Halfway to the unpaid-order deadline.'],
            'unpaid_cancelled' => ['label' => 'Unpaid order cancelled', 'group' => 'Buyers', 'when' => 'When an order is never paid.'],
            'price_drop' => ['label' => 'Wishlist price drop', 'group' => 'Buyers', 'when' => 'When a wishlist part gets cheaper. Can be turned off.'],
            'new_order' => ['label' => 'New order', 'group' => 'Sellers', 'when' => 'When a buyer pays for the seller’s items.'],
            'payout' => ['label' => 'Payout released', 'group' => 'Sellers', 'when' => 'When the buyer receives the order. Can be turned off.'],
            'application_received' => ['label' => 'Application received', 'group' => 'Sellers', 'when' => 'When someone applies to sell.'],
            'seller_approved' => ['label' => 'Application approved', 'group' => 'Sellers', 'when' => 'When an admin approves a seller.'],
            'seller_rejected' => ['label' => 'Application declined', 'group' => 'Sellers', 'when' => 'When an admin declines a seller.'],
            'team_invite' => ['label' => 'Team invitation', 'group' => 'Sellers', 'when' => 'When a store invites a staff member.'],
            'welcome' => ['label' => 'Welcome', 'group' => 'Accounts', 'when' => 'Right after someone creates an account.'],
            'password_reset' => ['label' => 'Reset password', 'group' => 'Accounts', 'when' => 'When someone uses “Forgot password”.'],
            'account_notice' => ['label' => 'Message from Panmotive', 'group' => 'Accounts', 'when' => 'Admin notices: suspensions, holds, removed listings.'],
        ];
    }

    /** @return array{subject: string, html: string} */
    public static function render(string $key): array
    {
        abort_unless(array_key_exists($key, self::catalog()), 404);

        // Nothing created for the preview may start a background job (search, SEO, webhooks).
        Queue::fake();

        DB::beginTransaction();
        try {
            return Product::withoutSyncingToSearch(function () use ($key) {
                [$notification, $notifiable] = self::make($key);
                $mail = $notification->toMail($notifiable);

                return ['subject' => (string) $mail->subject, 'html' => (string) $mail->render()];
            });
        } finally {
            DB::rollBack();
        }
    }

    /** @return array{0: Notification, 1: object} */
    private static function make(string $key): array
    {
        $s = self::samples();

        return match ($key) {
            'order_confirmation' => [new \App\Notifications\OrderConfirmation($s['order']), $s['buyer']],
            'order_shipped' => [new \App\Notifications\OrderShipped($s['order'], $s['shipped']), $s['buyer']],
            'order_delivered' => [new \App\Notifications\OrderDelivered($s['order'], $s['shipped']), $s['buyer']],
            'item_cancelled' => [new \App\Notifications\OrderItemCancelled($s['order']->items->last(), 'Out of stock', (float) $s['order']->items->last()->line_total), $s['buyer']],
            'payment_reminder' => [new \App\Notifications\OrderPaymentReminder($s['order']), $s['buyer']],
            'unpaid_cancelled' => [new \App\Notifications\UnpaidOrderCancelled($s['order']), (new AnonymousNotifiable)->route('mail', 'guest@example.com')],
            'price_drop' => [new \App\Notifications\PriceDropAlert($s['product'], 189.99, 159.99), $s['buyer']],
            'new_order' => [new \App\Notifications\NewOrderReceived($s['order']), $s['seller']],
            'payout' => [new \App\Notifications\SellerPayoutReleased($s['order']->items->first()), $s['seller']],
            'application_received' => [new \App\Notifications\SellerApplicationReceived('Rivera Auto Parts'), $s['seller']],
            'seller_approved' => [new \App\Notifications\SellerApproved, $s['seller']],
            'seller_rejected' => [new \App\Notifications\SellerRejected('We couldn’t verify the business details you gave. Please check the business name and EIN match your IRS letter.'), $s['seller']],
            'team_invite' => [new \App\Notifications\SellerTeamInvite($s['member']), (new AnonymousNotifiable)->route('mail', 'staff@example.com')],
            'welcome' => [new \App\Notifications\WelcomeToPanmotive, $s['buyer']],
            'password_reset' => [new ResetPassword('preview-token'), $s['buyer']],
            'account_notice' => [new \App\Notifications\AccountNotice('A listing was removed', 'We removed “Universal LED Headlight Kit” because it was listed in the wrong category. You can list it again in Lighting → Headlights.'), $s['seller']],
        };
    }

    /** @return array<string, mixed> */
    private static function samples(): array
    {
        $tag = Str::lower(Str::random(6));
        $buyer = User::create(['name' => 'Alex Rivera', 'email' => "preview-buyer-{$tag}@example.com", 'password' => Str::random(32)]);
        $seller = User::create(['name' => 'Jordan Lee', 'email' => "preview-seller-{$tag}@example.com", 'password' => Str::random(32)]);
        SellerProfile::create(['user_id' => $seller->id, 'business_name' => 'Lee Performance Parts', 'business_type' => 'llc', 'status' => 'approved', 'slug' => 'preview-'.$tag]);

        $category = Category::first() ?? Category::create(['name' => 'Preview', 'slug' => 'preview-'.$tag]);
        // A real listing with a photo makes the preview realistic (it's only read, never changed).
        $product = Product::whereHas('media')->latest('id')->first() ?? Product::create([
            'seller_id' => $seller->id, 'category_id' => $category->id, 'title' => 'Bosch QuietCast Front Brake Pads',
            'slug' => 'preview-pads-'.$tag, 'price' => 159.99, 'quantity' => 8, 'status' => 'active', 'sku' => 'BC905',
        ]);
        $second = Product::create([
            'seller_id' => $seller->id, 'category_id' => $category->id, 'title' => 'ACDelco Gold Engine Air Filter',
            'slug' => 'preview-filter-'.$tag, 'price' => 24.50, 'quantity' => 20, 'status' => 'active', 'sku' => 'A3144C',
        ]);

        $address = ['name' => 'Alex Rivera', 'line1' => '1450 Elm Street', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country' => 'US'];
        $order = Order::create([
            'order_number' => 'PM-'.strtoupper(Str::random(10)), 'buyer_id' => $buyer->id, 'status' => OrderStatus::Paid,
            'subtotal' => 208.99, 'shipping_total' => 0, 'tax_total' => 17.24, 'discount_total' => 10, 'credits_used' => 0, 'total' => 216.23,
            'shipping_address' => $address, 'billing_address' => $address, 'placed_at' => now(), 'stripe_payment_intent_id' => 'pi_preview',
        ]);
        foreach ([[$product, 1, (float) $product->price, null], [$second, 2, 24.50, null]] as [$p, $qty, $price, $variant]) {
            $line = round($qty * $price, 2);
            $order->items()->create([
                'seller_id' => $seller->id, 'product_id' => $p->id, 'product_title' => $p->title, 'variant_label' => $variant,
                'product_sku' => $p->sku, 'quantity' => $qty, 'unit_price' => $price, 'line_total' => $line,
                'commission_rate' => 10, 'platform_fee_amount' => round($line * .1, 2), 'seller_payout_amount' => round($line * .9, 2),
                'status' => OrderStatus::Shipped, 'carrier' => 'UPS', 'tracking_number' => '1Z999AA10123456784', 'shipped_at' => now(),
            ]);
        }
        $subtotal = (float) $order->items()->sum('line_total');
        $order->update(['subtotal' => $subtotal, 'total' => round($subtotal + 17.24 - 10, 2)]);
        $order->load('items');

        $member = SellerTeamMember::create(['owner_id' => $seller->id, 'email' => 'staff@example.com', 'name' => 'Sam', 'role' => 'staff', 'status' => 'invited', 'invited_at' => now()]);

        return [
            'buyer' => $buyer, 'seller' => $seller->fresh(), 'product' => $product, 'order' => $order,
            'shipped' => $order->items, 'member' => $member,
        ];
    }
}
