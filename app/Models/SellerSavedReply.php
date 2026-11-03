<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A canned response a seller can drop into a message or Q&A answer, with {placeholders}. */
class SellerSavedReply extends Model
{
    protected $fillable = ['seller_id', 'title', 'body', 'use_count'];

    public const PLACEHOLDERS = [
        '{buyer_first_name}' => "Buyer's first name",
        '{buyer_name}' => "Buyer's full name",
        '{store_name}' => 'Your store name',
        '{product_title}' => 'The listing the thread is about',
        '{order_number}' => 'Order number',
        '{tracking_number}' => 'Latest tracking number',
        '{tracking_url}' => 'Carrier tracking link',
    ];

    /** Starter set offered to sellers with no saved replies yet. */
    public const STARTERS = [
        ['Thanks for your order', "Hi {buyer_first_name}, thanks for your order {order_number}! We're getting it ready and you'll get tracking as soon as it ships.\n\n— {store_name}"],
        ['Shipped + tracking', "Good news {buyer_first_name} — your order {order_number} is on its way. Tracking: {tracking_number}\n{tracking_url}"],
        ['Fitment check (need VIN)', "Hi {buyer_first_name}, happy to confirm fitment for {product_title}. Could you send your 17-character VIN (or year, make, model, trim and engine)? We'll double-check before you buy."],
        ['Return instructions', "Sorry this didn't work out, {buyer_first_name}. Please start a return from your order page ({order_number}) and we'll send instructions within one business day."],
        ['Back in stock soon', "Thanks for asking! {product_title} is restocking shortly — follow our store and you'll be the first to know."],
    ];

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    /** Fill placeholders; any we can't resolve are removed rather than left as {raw_tokens}. */
    public static function interpolate(string $body, array $vars): string
    {
        $out = strtr($body, array_map(fn ($v) => (string) $v, $vars));
        $out = preg_replace('/\{(?:'.implode('|', array_map(fn ($k) => trim($k, '{}'), array_keys(self::PLACEHOLDERS))).')\}/', '', $out);

        // Tidy what a removed token leaves behind ("Hi , thanks" → "Hi, thanks").
        return trim(preg_replace(['/ +([,.!?])/', '/[ \t]{2,}/'], ['$1', ' '], $out));
    }
}
