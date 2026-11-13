<?php

namespace App\Support;

use App\Models\EmailTemplate;
use Illuminate\Support\Facades\Cache;

/**
 * The words in each email, editable by the admin. Each email has a subject, a preview line
 * (shown after the subject in the inbox), a greeting, a main message, a button label and a
 * closing message. Placeholders like {order_number} are filled in when the email is sent.
 *
 * The order lines, totals, tracking boxes and links stay automatic: only the words change, so
 * an edit can never break an order email. Anything left blank falls back to the default here.
 */
class EmailTemplates
{
    public const FIELDS = ['subject', 'preheader', 'greeting', 'intro', 'button', 'outro'];

    private const CACHE_KEY = 'email_templates.v1';

    /** @return array<string, array<string, mixed>> */
    public static function defaults(): array
    {
        $hi = 'Hi {first_name},';

        return [
            'order_confirmation' => [
                'subject' => 'Order confirmed: {order_number}',
                'preheader' => 'Thanks for your order. We’ll email you again as soon as it ships.',
                'greeting' => $hi,
                'intro' => 'Thanks for your order! We’ve told the seller, and we’ll email you again as soon as it ships.',
                'button' => 'View your order',
                'outro' => 'Need to change something? Message the seller from your order page as soon as you can, before it ships.',
                'vars' => ['first_name' => 'Buyer’s first name', 'order_number' => 'Order number', 'order_total' => 'Total paid'],
            ],
            'order_shipped' => [
                'subject' => 'Your order {order_number} has shipped',
                'preheader' => 'Good news: your order is on its way. {carrier} tracking {tracking_number}.',
                'greeting' => $hi,
                'intro' => 'Good news: your order is on its way from {store}.',
                'button' => 'Track your package',
                'outro' => 'Tracking can take up to a day to show the first scan.',
                'vars' => ['first_name' => 'Buyer’s first name', 'order_number' => 'Order number', 'carrier' => 'Carrier (UPS, USPS…)', 'tracking_number' => 'Tracking number', 'store' => 'Store name'],
            ],
            'order_delivered' => [
                'subject' => 'Delivered: your order {order_number}',
                'preheader' => 'Your package has arrived. Tell us how it went.',
                'greeting' => $hi,
                'intro' => "Your package has been delivered. We hope the parts fit perfectly!\n\nSomething wrong? Wrong part, damaged, or not there? You can ask for a return from your order page within {return_days} days.",
                'button' => 'Leave a review',
                'outro' => 'Reviews help other drivers find the right part, and help good sellers grow.',
                'vars' => ['first_name' => 'Buyer’s first name', 'order_number' => 'Order number', 'return_days' => 'Return window in days'],
            ],
            'item_cancelled' => [
                'subject' => 'Item cancelled from order {order_number}',
                'preheader' => 'Your refund for {product} is on its way.',
                'greeting' => $hi,
                'intro' => 'The seller had to cancel “{product}” from your order {order_number}.',
                'button' => 'View your order',
                'outro' => 'We’re sorry for the inconvenience.',
                'vars' => ['first_name' => 'Buyer’s first name', 'order_number' => 'Order number', 'product' => 'Part name', 'reason' => 'Why it was cancelled', 'refund' => 'Amount refunded'],
            ],
            'payment_reminder' => [
                'subject' => 'Finish paying for order {order_number}',
                'preheader' => 'We’re holding your parts until {deadline}.',
                'greeting' => $hi,
                'intro' => 'Your order isn’t paid yet. We’re holding these parts for you until {deadline}.',
                'button' => 'Pay now',
                'outro' => 'If your card was declined, you can try a different card on the same page. After the deadline the order is cancelled and you won’t be charged.',
                'vars' => ['first_name' => 'Buyer’s first name', 'order_number' => 'Order number', 'deadline' => 'Payment deadline', 'order_total' => 'Amount to pay'],
            ],
            'unpaid_cancelled' => [
                'subject' => 'Order {order_number} was cancelled',
                'preheader' => 'We didn’t receive payment, so you weren’t charged.',
                'greeting' => $hi,
                'intro' => 'We didn’t receive payment for this order in time, so we cancelled it. You have not been charged.',
                'button' => 'Shop these parts again',
                'outro' => 'Still need them? Add them to your cart again. They may still be in stock.',
                'vars' => ['first_name' => 'Buyer’s first name', 'order_number' => 'Order number'],
            ],
            'price_drop' => [
                'subject' => 'Price drop: {product} is now {new_price}',
                'preheader' => '{percent}% off a part on your wishlist.',
                'greeting' => $hi,
                'intro' => 'A part on your wishlist just got cheaper.',
                'button' => 'View the part',
                'outro' => 'Prices and stock can change at any time.',
                'vars' => ['first_name' => 'Buyer’s first name', 'product' => 'Part name', 'old_price' => 'Old price', 'new_price' => 'New price', 'percent' => 'Percent off', 'savings' => 'Amount saved'],
            ],
            'back_in_stock' => [
                'subject' => 'Back in stock: {product}',
                'preheader' => 'The part you asked about is available again. Stock is limited.',
                'greeting' => $hi,
                'intro' => 'Good news: the part you asked us to watch is back in stock.',
                'button' => 'Get it now',
                'outro' => 'Stock can sell out again quickly, so we can’t hold it for you.',
                'vars' => ['first_name' => 'Buyer’s first name', 'product' => 'Part name', 'price' => 'Price'],
            ],
            'did_it_fit' => [
                'subject' => 'Did everything fit?',
                'preheader' => 'Tell us how it went, or get help if something’s wrong.',
                'greeting' => $hi,
                'intro' => 'Your order {order_number} arrived a few days ago. We hope the install went smoothly!',
                'button' => 'Write a review',
                'outro' => 'Did it fit and work? A quick, honest review (good or bad) tells other owners of your vehicle whether this part is right for them.',
                'vars' => ['first_name' => 'Buyer’s first name', 'order_number' => 'Order number'],
            ],
            'browse' => [
                'subject' => 'Still looking for the right part?',
                'preheader' => 'The parts you looked at are still in stock.',
                'greeting' => $hi,
                'intro' => 'You looked at these parts recently. They’re still in stock if you need them.',
                'button' => 'Take another look',
                'outro' => 'Not sure it fits? Add your vehicle and look for the green “Fits your vehicle” check. Those parts are covered by the Panmotive Fit Guarantee.',
                'vars' => ['first_name' => 'Buyer’s first name', 'product' => 'First part they looked at'],
            ],
            'garage_tip' => [
                'subject' => 'Add your vehicle and only see parts that fit',
                'preheader' => 'Takes 10 seconds. Parts listed for your vehicle are covered by the Fit Guarantee.',
                'greeting' => $hi,
                'intro' => 'The fastest way to find the right part: tell us what you drive.',
                'button' => 'Add your vehicle',
                'outro' => 'It takes about 10 seconds: pick the year, make and model.',
                'vars' => ['first_name' => 'Buyer’s first name'],
            ],
            'winback' => [
                'subject' => 'New parts for your {vehicle}',
                'preheader' => 'Fresh listings from Panmotive sellers.',
                'greeting' => $hi,
                'intro' => 'It’s been a while! Here are some parts listed for your {vehicle}. Each one shows the green “Fits” check and is covered by the Fit Guarantee.',
                'button' => 'Shop parts',
                'outro' => '',
                'vars' => ['first_name' => 'Buyer’s first name', 'vehicle' => 'Their vehicle (e.g. 2018 Toyota Camry)'],
                'note' => 'Used when the buyer has a vehicle in their garage. Buyers without one get a general version.',
            ],
            'new_order' => [
                'subject' => 'New order {order_number}: you’ll earn {earnings}',
                'preheader' => 'Ship it by {ship_by}. The buyer has paid.',
                'greeting' => $hi,
                'intro' => 'Good news: you have a new paid order. Accept it and ship it on time to keep your seller rating high.',
                'button' => 'Accept and ship',
                'outro' => 'You’ll be paid after the buyer receives the order.',
                'vars' => ['first_name' => 'Seller’s first name', 'order_number' => 'Order number', 'earnings' => 'What they’ll earn', 'ship_by' => 'Ship-by date', 'ship_to' => 'Buyer’s city and state'],
            ],
            'payout' => [
                'subject' => 'You’ve been paid {amount} for order {order_number}',
                'preheader' => 'The buyer received their order, so your money is on its way.',
                'greeting' => $hi,
                'intro' => 'The buyer received their order, so we’ve released your money.',
                'button' => 'See your payouts',
                'outro' => 'It usually reaches your bank in 2–3 business days, depending on your bank.',
                'vars' => ['first_name' => 'Seller’s first name', 'amount' => 'Amount paid', 'order_number' => 'Order number'],
            ],
            'application_received' => [
                'subject' => 'We received your seller application',
                'preheader' => 'Our team usually reviews applications within 1–2 business days.',
                'greeting' => $hi,
                'intro' => 'Thanks for applying to sell on Panmotive as {store}. Our team will review your application, usually within 1–2 business days.',
                'button' => 'Finish your setup',
                'outro' => 'While you wait, you can finish your setup: add your shipping settings and connect payouts, so you can start selling the moment you’re approved.',
                'vars' => ['first_name' => 'Seller’s first name', 'store' => 'Store name'],
            ],
            'seller_approved' => [
                'subject' => 'You’re approved! Your Panmotive store is ready',
                'preheader' => 'Add your first listing and start selling.',
                'greeting' => $hi,
                'intro' => 'Great news: {store} has been approved, and you can start selling on Panmotive today.',
                'button' => 'Go to your seller dashboard',
                'outro' => 'Listings with clear photos and exact fitment (year, make, model) sell faster and get fewer returns.',
                'vars' => ['first_name' => 'Seller’s first name', 'store' => 'Store name'],
            ],
            'seller_rejected' => [
                'subject' => 'Update on your Panmotive seller application',
                'preheader' => 'We couldn’t approve your application this time.',
                'greeting' => $hi,
                'intro' => 'Thank you for applying to sell on Panmotive. We reviewed your application and couldn’t approve it this time.',
                'button' => '',
                'outro' => 'If you can fix this, or think we got it wrong, just reply to this email and our team will take another look.',
                'vars' => ['first_name' => 'Seller’s first name'],
                'note' => 'The reason you type when declining is shown in a box after the main message.',
            ],
            'team_invite' => [
                'subject' => 'You’ve been invited to help run {store} on Panmotive',
                'preheader' => '{store} added you to their seller team.',
                'greeting' => 'Hello!',
                'intro' => '{store} has invited you to their seller team with the role of “{role}”.',
                'button' => 'Accept invitation',
                'outro' => "If you don’t have a Panmotive account yet, you’ll be able to create one — use this same email address.\n\nThis invitation expires in {days} days.",
                'vars' => ['store' => 'Store name', 'role' => 'Their role', 'days' => 'Days until the invite expires'],
            ],
            'welcome' => [
                'subject' => 'Welcome to Panmotive',
                'preheader' => 'Find the right part for your vehicle, from trusted US sellers.',
                'greeting' => $hi,
                'intro' => 'Thanks for joining Panmotive, the marketplace for auto parts from trusted sellers across the US.',
                'button' => 'Start shopping',
                'outro' => 'Have parts to sell? You can open a store from your account at any time.',
                'vars' => ['first_name' => 'Their first name'],
            ],
            'password_reset' => [
                'subject' => 'Reset your Panmotive password',
                'preheader' => 'This link works for {minutes} minutes.',
                'greeting' => $hi,
                'intro' => 'We got a request to reset the password for your Panmotive account.',
                'button' => 'Choose a new password',
                'outro' => "This link works for {minutes} minutes and can only be used once.\n\nDidn’t ask for this? You can ignore this email. Your password stays the same, and no one can change it without this link.",
                'vars' => ['first_name' => 'Their first name', 'minutes' => 'Minutes the link works'],
            ],
        ];
    }

    public static function editable(string $key): bool
    {
        return array_key_exists($key, self::defaults());
    }

    /** Saved changes, keyed by email. */
    public static function saved(): array
    {
        try {
            return Cache::rememberForever(self::CACHE_KEY, fn () => EmailTemplate::all()->keyBy('key')
                ->map(fn ($t) => $t->only(self::FIELDS))->all());
        } catch (\Throwable) {
            return []; // before the table exists
        }
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The words for one email, with the admin's changes and placeholders filled in.
     *
     * @param  array<string, string>|null  $draft  unsaved changes, for a live preview
     * @return array<string, string>
     */
    public static function get(string $key, array $vars = [], ?array $draft = null): array
    {
        $default = self::defaults()[$key] ?? [];
        $saved = $draft ?? (self::saved()[$key] ?? []);

        $out = [];
        foreach (self::FIELDS as $field) {
            $value = $saved[$field] ?? null;
            $out[$field] = self::fill($value === null ? ($default[$field] ?? '') : (string) $value, $vars);
        }

        return $out;
    }

    /** Replace {placeholders}. Unknown ones are left as typed so a mistake is visible, not silent. */
    public static function fill(string $text, array $vars): string
    {
        return preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0], $text);
    }

    /** Placeholders in the text that this email doesn't offer. */
    public static function unknownPlaceholders(string $key, string $text): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $text, $m);

        return array_values(array_diff(array_unique($m[1]), array_keys(self::defaults()[$key]['vars'] ?? [])));
    }

    /** Paragraphs: a blank line starts a new one. */
    public static function paragraphs(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split("/\R\s*\R/", trim($text)) ?: []), fn ($p) => $p !== ''));
    }
}
