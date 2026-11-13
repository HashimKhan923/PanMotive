<?php

namespace App\Services\Marketing\Social;

use App\Models\MarketingEvent;
use App\Models\Product;
use App\Models\PromoLink;
use App\Models\SellerProfile;
use App\Models\SocialPost;
use App\Services\Marketing\MarketingCopyService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** The Social studio's helpers: hashtags, best time to post, a week's plan, the auto-queue, link in bio. */
class SocialToolkit
{
    public function __construct(private MarketingCopyService $copy) {}

    /** Hashtag ideas for a part: its category, brand and the vehicles it fits, plus a few car-community favourites. */
    public function hashtags(?Product $product): array
    {
        $tags = collect();
        if ($product) {
            $product->loadMissing('category', 'brand', 'fitments.make', 'fitments.model');
            $tags->push($product->category?->name, $product->brand?->name);
            foreach ($product->fitments->take(4) as $v) {
                $tags->push(trim(($v->make?->name ?? '').($v->model?->name ?? '')), $v->make?->name);
            }
        }
        $tags = $tags->concat(['carparts', 'autoparts', 'diycarrepair', 'carlifestyle', 'mechaniclife', 'cartok', 'projectcar']);

        return $tags->filter()->map(fn ($t) => '#'.Str::lower(preg_replace('/[^A-Za-z0-9]/', '', (string) $t)))
            ->filter(fn ($t) => strlen($t) > 2)->unique()->take(15)->values()->all();
    }

    /** The hour when this store's social links get the most clicks, or 6 pm (a good default for car parts). */
    public function bestHour(int $sellerId): array
    {
        $byHour = MarketingEvent::where('seller_id', $sellerId)->whereIn('channel', ['social', 'bio'])->where('kind', 'click')
            ->where('created_at', '>=', now()->subDays(90))->pluck('created_at')
            ->groupBy(fn ($t) => (int) $t->format('G'))->map->count();

        if ($byHour->sum() < 10) {
            return ['hour' => 18, 'reason' => 'Evenings (6–8 pm) are when most people browse car parts. We’ll learn your best time as clicks come in.'];
        }
        $hour = (int) $byHour->sortDesc()->keys()->first();

        return ['hour' => $hour, 'reason' => 'Your posts get the most clicks around '.Carbon::createFromTime($hour)->format('g A').'.'];
    }

    /** Products to feature next: in stock, live, and not posted about recently. */
    public function nextProducts(int $sellerId, int $count): Collection
    {
        $recent = SocialPost::where('seller_id', $sellerId)->where('created_at', '>=', now()->subDays(21))->whereNotNull('product_id')->pluck('product_id');

        $pick = fn ($q) => $q->listed()->where('seller_id', $sellerId)->where('quantity', '>', 0)->whereHas('media')->with('media');
        $products = $pick(Product::query())->whereNotIn('id', $recent)->inRandomOrder()->take($count)->get();
        if ($products->count() < $count) {
            $products = $products->concat($pick(Product::query())->whereNotIn('id', $products->pluck('id'))->inRandomOrder()->take($count - $products->count())->get());
        }

        return $products;
    }

    /**
     * Create scheduled posts with AI captions for the next $days days (one product a day, at the
     * store's best hour). Each becomes a share kit (or auto-post) when its time comes.
     */
    public function planWeek(int $sellerId, array $channels, int $days = 7, string $tone = 'friendly'): int
    {
        $hour = $this->bestHour($sellerId)['hour'];
        $made = 0;
        foreach ($this->nextProducts($sellerId, $days)->values() as $i => $product) {
            $at = now()->addDays($i + 1)->setTime($hour, 0);
            $this->schedule($sellerId, $product, $channels, $at, $tone);
            $made++;
        }

        return $made;
    }

    /** Daily auto-queue: if switched on and nothing is planned for today, line up one product post. */
    public function runQueue(): int
    {
        $made = 0;
        SellerProfile::whereNotNull('social_settings')->where('status', 'approved')->each(function (SellerProfile $sp) use (&$made) {
            $q = $sp->social_settings['queue'] ?? [];
            if (empty($q['enabled']) || empty($q['channels'])) {
                return;
            }
            $hour = (int) ($q['hour'] ?? 18);
            $today = now()->setTime($hour, 0);
            if (! in_array((int) now()->dayOfWeekIso, array_map('intval', $q['days'] ?? [1, 2, 3, 4, 5, 6, 7]), true)) {
                return;
            }
            $already = SocialPost::where('seller_id', $sp->user_id)->whereBetween('scheduled_at', [now()->startOfDay(), now()->endOfDay()])->exists()
                || SocialPost::where('seller_id', $sp->user_id)->whereNull('scheduled_at')->where('created_at', '>=', now()->startOfDay())->exists();
            if ($already) {
                return;
            }
            $product = $this->nextProducts($sp->user_id, 1)->first();
            if ($product) {
                $this->schedule($sp->user_id, $product, $q['channels'], $today->isPast() ? now()->addMinutes(5) : $today, $q['tone'] ?? 'friendly');
                $made++;
            }
        });

        return $made;
    }

    private function schedule(int $sellerId, Product $product, array $channels, Carbon $at, string $tone): SocialPost
    {
        $product->loadMissing('brand', 'category');
        $media = $product->getFirstMedia('images');
        $post = SocialPost::create([
            'seller_id' => $sellerId, 'product_id' => $product->id,
            'body' => $this->copy->socialCaption($product, $channels[0] ?? 'facebook', $tone),
            'link_url' => route('products.show', $product->slug),
            'media_path' => $media?->getPathRelativeToRoot(),
            'status' => 'scheduled', 'scheduled_at' => $at,
        ]);
        foreach (array_unique($channels) as $platform) {
            if (! PlatformRegistry::config($platform)) {
                continue;
            }
            $link = PromoLink::create(['seller_id' => $sellerId, 'product_id' => $product->id, 'label' => 'Social · '.PlatformRegistry::label($platform).' · '.$at->format('M j')]);
            $post->targets()->create([
                'promo_link_id' => $link->id, 'platform' => $platform, 'status' => 'pending',
                'caption' => count($channels) > 1 ? $this->copy->socialCaption($product, $platform, $tone) : null,
            ]);
        }

        return $post;
    }

    /** Link-in-bio settings, with defaults. */
    public static function bio(SellerProfile $sp): array
    {
        return array_merge(['headline' => 'Shop our parts', 'product_ids' => [], 'coupon_id' => null, 'show_deals' => true, 'enabled' => true], $sp->social_settings['bio'] ?? []);
    }

    /** One tracked link per bio item, made the first time it's needed (so clicks and sales count). */
    public static function bioLink(SellerProfile $sp, ?int $productId): PromoLink
    {
        return PromoLink::firstOrCreate(
            ['seller_id' => $sp->user_id, 'product_id' => $productId, 'label' => 'Link in bio'.($productId ? ' · product '.$productId : ' · store')],
        );
    }
}
