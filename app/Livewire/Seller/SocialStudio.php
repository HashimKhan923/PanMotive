<?php

namespace App\Livewire\Seller;

use App\Jobs\PublishSocialPost;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\PromoLink;
use App\Models\Review;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Services\Marketing\MarketingCopyService;
use App\Services\Marketing\Social\PlatformRegistry;
use App\Services\Marketing\Social\SocialToolkit;
use App\Support\SellerContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Write once, post everywhere. Channels with a real connected account auto-post; every other
 * channel gets a share kit (caption sized for the platform, image, tracked link, share button).
 * Each channel gets its own tracked link, so clicks and orders are real numbers, not estimates.
 *
 * Also: a branded image maker, previews per app, a caption per app, hashtag ideas, a calendar
 * with "plan my week" and a daily auto-queue, results by app, and the store's link-in-bio page.
 */
#[Layout('layouts.seller')]
class SocialStudio extends Component
{
    use WithFileUploads;

    #[Url]
    public string $tab = 'compose';

    #[Url]
    public ?string $month = null;

    /** A saved design from the Social designer, attached as the post image. */
    #[Url]
    public ?int $design = null;

    /** One image per app, made by "Create with AI for every app". */
    public array $channelImages = [];

    public string $aiGoal = 'new';

    // compose
    public string $body = '';
    public ?int $productId = null;
    public array $channels = ['facebook'];
    public array $captions = [];
    public $media;
    public string $when = 'now';
    public ?string $scheduledAt = null;
    public string $tone = 'friendly';

    // auto-queue + link in bio
    public array $queue = [];
    public array $bio = [];

    public function mount(): void
    {
        $sp = auth()->user()->sellerProfile;
        $this->queue = array_merge(['enabled' => false, 'hour' => 18, 'days' => [1, 2, 3, 4, 5, 6, 7], 'channels' => ['facebook', 'instagram'], 'tone' => 'friendly'], $sp?->social_settings['queue'] ?? []);
        $this->bio = $sp ? SocialToolkit::bio($sp) : [];
    }

    public function generate(MarketingCopyService $copy): void
    {
        $platform = $this->channels[0] ?? 'facebook';
        $product = $this->productId ? Product::where('seller_id', auth()->id())->find($this->productId) : null;
        $product?->loadMissing('brand', 'category');

        $this->body = $copy->socialCaption($product, $platform, $this->tone);
        $this->dispatch('toast', message: 'Caption drafted — tweak and post.');
    }

    /** One caption per selected app, each sized and styled for that app. */
    public function generateAll(MarketingCopyService $copy): void
    {
        $product = $this->productId ? Product::where('seller_id', auth()->id())->find($this->productId) : null;
        $product?->loadMissing('brand', 'category');
        foreach (array_unique($this->channels) as $platform) {
            if (PlatformRegistry::config($platform)) {
                $this->captions[$platform] = $copy->socialCaption($product, $platform, $this->tone);
            }
        }
        $this->body = $this->body !== '' ? $this->body : (string) reset($this->captions);
        $this->dispatch('toast', message: 'A caption for each app is ready. Edit any of them below.');
    }

    /**
     * "Create with AI for every app": a caption for each app now, and an image for each app drawn
     * in the browser from the best-suited template (so Pinterest gets a tall pin, X a wide card).
     */
    public function aiCreate(MarketingCopyService $copy): void
    {
        abort_unless(in_array($this->aiGoal, ['new', 'sale', 'fitment', 'restock', 'review', 'tip'], true), 422);
        $this->validate(['channels' => 'required|array|min:1'], ['channels.required' => 'Pick at least one app first.']);
        $this->generateAll($copy);
        $this->channelImages = [];
        $this->dispatch('pm-ai-render', goal: $this->aiGoal, platforms: array_values(array_unique($this->channels)), data: $this->templateData());
    }

    /** What the image templates fill in: the chosen product, store, logo, colour and a review. */
    private function templateData(): array
    {
        $sp = auth()->user()->sellerProfile;
        $p = $this->productId ? Product::where('seller_id', auth()->id())->with('media', 'fitments.make', 'fitments.model')->find($this->productId)
            : Product::where('seller_id', auth()->id())->where('status', 'active')->whereHas('media')->with('media', 'fitments.make', 'fitments.model')->latest('id')->first();
        $review = Review::whereHas('product', fn ($q) => $q->where('seller_id', auth()->id()))->where('status', 'published')->where('rating', '>=', 4)->with('user:id,name')->latest()->first();
        $price = $p ? $p->effectivePrice() : null;

        return [
            'title' => $p?->title ?? ($sp?->business_name ?? 'Our store'),
            'price' => $price !== null ? '$'.number_format($price, 2) : '',
            'was' => $p && $price < (float) $p->price ? '$'.number_format((float) $p->price, 2) : null,
            'off' => $p && $price < (float) $p->price && (float) $p->price > 0 ? (int) round((1 - $price / (float) $p->price) * 100) : null,
            'fits' => $p?->fitments->first() ? trim($p->fitments->first()->year.' '.$p->fitments->first()->make?->name.' '.$p->fitments->first()->model?->name) : null,
            'image' => $p && $p->getFirstMedia('images') ? route('seller.social.image', $p) : null,
            'store' => $sp?->business_name ?? 'My store',
            'logo' => $sp?->logo_path ? route('seller.social.logo') : null,
            'color' => \App\Services\Marketing\CampaignDesign::brand($sp?->email_brand)['color'],
            'review' => $review ? ['text' => Str::limit((string) $review->body, 180), 'name' => Str::before((string) $review->user?->name, ' ') ?: 'A customer', 'rating' => (int) $review->rating] : null,
            'headline' => ['new' => 'Just landed', 'sale' => 'Sale on now', 'fitment' => 'Made for your car', 'restock' => 'Back in stock', 'review' => 'What customers say', 'tip' => 'Tip of the week'][$this->aiGoal] ?? null,
        ];
    }

    public function clearCaption(string $platform): void
    {
        unset($this->captions[$platform]);
    }

    public function publish(): void
    {
        abort_unless(SellerContext::can('marketing'), 403);
        $this->validate([
            'body' => 'required|string|max:5000',
            'channels' => 'required|array|min:1',
            'channels.*' => 'in:'.implode(',', array_keys(PlatformRegistry::all())),
            'captions.*' => 'nullable|string|max:5000',
            'media' => 'nullable|image|max:8192',
            'channelImages.*' => 'nullable|image|max:12288',
            'scheduledAt' => 'nullable|date|after:now',
        ], ['channels.required' => 'Pick at least one channel.']);

        $product = $this->productId ? Product::where('seller_id', auth()->id())->find($this->productId) : null;
        $path = $this->media ? $this->media->store('social/'.auth()->id(), 'public') : null;
        $designImage = $this->design ? \App\Models\SocialDesign::where('seller_id', auth()->id())->find($this->design)?->image_path : null;
        $perApp = collect($this->channelImages)->filter(fn ($f) => is_object($f) && method_exists($f, 'store'))
            ->map(fn ($f) => $f->store('social/'.auth()->id(), 'public'));
        $path ??= $designImage ?? $perApp->first();
        $scheduled = $this->when === 'later' && $this->scheduledAt ? Carbon::parse($this->scheduledAt) : null;
        $accounts = SocialAccount::where('seller_id', auth()->id())->where('status', 'connected')->pluck('id', 'platform');

        $post = SocialPost::create([
            'seller_id' => auth()->id(),
            'product_id' => $product?->id,
            'body' => $this->body,
            'link_url' => $product ? route('products.show', $product->slug) : null,
            'media_path' => $path,
            'status' => $scheduled ? 'scheduled' : 'draft',
            'scheduled_at' => $scheduled,
        ]);

        foreach (array_unique($this->channels) as $platform) {
            $link = PromoLink::create([
                'seller_id' => auth()->id(),
                'product_id' => $product?->id,
                'label' => 'Social · '.PlatformRegistry::label($platform).' · '.now()->format('M j'),
            ]);
            $caption = trim((string) ($this->captions[$platform] ?? ''));
            $post->targets()->create([
                'social_account_id' => $accounts[$platform] ?? null,
                'promo_link_id' => $link->id,
                'platform' => $platform,
                'status' => 'pending',
                'caption' => $caption !== '' && $caption !== trim($this->body) ? $caption : null,
                'media_path' => $perApp[$platform] ?? null,
            ]);
        }

        if (! $scheduled) {
            PublishSocialPost::dispatchSync($post->id);
            $post->refresh()->load('targets');
            $auto = $post->targets->where('status', 'published')->count();
            $kits = $post->targets->where('status', 'share_kit')->count();
            $this->dispatch('toast', message: collect([
                $auto ? "Posted to {$auto} ".Str::plural('channel', $auto) : null,
                $kits ? "{$kits} share ".Str::plural('kit', $kits).' ready — tap Share on each' : null,
            ])->filter()->implode(' · ').'.');
        } else {
            $this->dispatch('toast', message: 'Scheduled for '.$scheduled->format('M j, g:i A').' — share kits will be ready then.');
        }

        $this->reset('body', 'productId', 'media', 'when', 'scheduledAt', 'captions', 'channelImages', 'design');
        $this->tab = 'posts';
    }

    /** Fill the composer from an earlier post ("Post again"). */
    public function postAgain(int $postId): void
    {
        $post = SocialPost::where('seller_id', auth()->id())->with('targets')->findOrFail($postId);
        $this->body = $post->body;
        $this->productId = $post->product_id;
        $this->channels = $post->targets->pluck('platform')->unique()->values()->all() ?: ['facebook'];
        $this->captions = $post->targets->filter(fn ($t) => $t->caption)->mapWithKeys(fn ($t) => [$t->platform => $t->caption])->all();
        $this->tab = 'compose';
        $this->dispatch('toast', message: 'Loaded into the composer. Add a fresh image if you like, then post.');
    }

    /** "Plan my week": seven scheduled posts with AI captions, one product a day. */
    public function planWeek(SocialToolkit $toolkit): void
    {
        abort_unless(SellerContext::can('marketing'), 403);
        $channels = array_values(array_intersect($this->channels ?: ['facebook', 'instagram'], array_keys(PlatformRegistry::all())));
        $made = $toolkit->planWeek(auth()->id(), $channels ?: ['facebook'], 7, $this->tone);
        $this->dispatch('toast', message: $made ? "{$made} posts planned for the next ".($made === 1 ? 'day' : $made.' days').'. Edit or move them on the calendar.' : 'Add photos to your in-stock listings first, then try again.');
        $this->tab = 'calendar';
    }

    /** Drag a post to another day on the calendar (it keeps its time). */
    public function reschedule(int $postId, string $date): void
    {
        $post = SocialPost::where('seller_id', auth()->id())->findOrFail($postId);
        abort_unless($post->status === 'scheduled', 422, 'Only scheduled posts can be moved.');
        $day = Carbon::createFromFormat('Y-m-d', $date);
        $at = $day->setTime($post->scheduled_at?->hour ?? 18, $post->scheduled_at?->minute ?? 0);
        if ($at->isPast()) {
            $this->dispatch('toast', message: 'Pick a day in the future.');

            return;
        }
        $post->update(['scheduled_at' => $at]);
        $this->dispatch('toast', message: 'Moved to '.$at->format('D, M j g:i A').'.');
    }

    public function saveQueue(): void
    {
        abort_unless(SellerContext::can('marketing'), 403);
        $q = $this->validate([
            'queue.enabled' => 'boolean',
            'queue.hour' => 'integer|min:0|max:23',
            'queue.days' => 'array',
            'queue.days.*' => 'integer|min:1|max:7',
            'queue.channels' => 'array|min:1',
            'queue.channels.*' => 'in:'.implode(',', array_keys(PlatformRegistry::all())),
            'queue.tone' => 'in:friendly,expert,hype,deal',
        ], ['queue.channels.min' => 'Pick at least one app for the queue.'])['queue'];
        $this->storeSettings('queue', $q);
        $this->dispatch('toast', message: $q['enabled'] ? 'Auto-queue is on: one product post a day.' : 'Auto-queue is off.');
    }

    public function saveBio(): void
    {
        abort_unless(SellerContext::can('marketing'), 403);
        $b = $this->validate([
            'bio.enabled' => 'boolean',
            'bio.headline' => 'nullable|string|max:80',
            'bio.product_ids' => 'array|max:12',
            'bio.product_ids.*' => 'integer',
            'bio.coupon_id' => 'nullable|integer',
            'bio.show_deals' => 'boolean',
        ])['bio'];
        $b['product_ids'] = Product::where('seller_id', auth()->id())->whereIn('id', $b['product_ids'] ?? [])->pluck('id')->all();
        $b['coupon_id'] = $b['coupon_id'] && Coupon::where('seller_id', auth()->id())->whereNull('user_id')->whereKey($b['coupon_id'])->exists() ? (int) $b['coupon_id'] : null;
        $this->bio = array_merge($this->bio, $b);
        $this->storeSettings('bio', $this->bio);
        $this->dispatch('toast', message: 'Link-in-bio page saved.');
    }

    private function storeSettings(string $key, array $value): void
    {
        $sp = auth()->user()->sellerProfile;
        $settings = $sp->social_settings ?? [];
        $settings[$key] = $value;
        $sp->update(['social_settings' => $settings]);
    }

    /** Seller confirms they posted a share kit — keeps the calendar honest about what's live. */
    public function markShared(int $targetId): void
    {
        $target = SocialPostTarget::whereHas('post', fn ($q) => $q->where('seller_id', auth()->id()))->findOrFail($targetId);
        abort_unless($target->status === 'share_kit', 422);
        $target->update(['status' => 'shared', 'published_at' => now()]);
        $target->post->recomputeStatus();
    }

    public function retry(int $postId): void
    {
        $post = SocialPost::where('seller_id', auth()->id())->findOrFail($postId);
        $post->targets()->whereIn('status', ['failed', 'skipped'])->update(['status' => 'pending', 'error' => null]);
        PublishSocialPost::dispatchSync($post->id);
        $this->dispatch('toast', message: 'Retried.');
    }

    public function deletePost(int $postId): void
    {
        abort_unless(SellerContext::can('marketing'), 403);
        SocialPost::where('seller_id', auth()->id())->whereKey($postId)->delete();
    }

    public function render(SocialToolkit $toolkit)
    {
        $sellerId = auth()->id();
        $sp = auth()->user()->sellerProfile;
        $accounts = SocialAccount::where('seller_id', $sellerId)->get()->keyBy('platform');

        $posts = SocialPost::where('seller_id', $sellerId)
            ->with('targets.promoLink', 'product:id,title,slug')
            ->latest('scheduled_at')->latest()
            ->limit(120)->get();
        $targets = $posts->flatMap->targets;

        $month = $this->month && preg_match('/^\d{4}-\d{2}$/', $this->month) ? Carbon::createFromFormat('Y-m-d', $this->month.'-01') : now()->startOfMonth();
        $products = Product::where('seller_id', $sellerId)->where('status', 'active')->with(['media', 'fitments.make', 'fitments.model'])->orderBy('title')->limit(400)->get();
        $product = $this->productId ? $products->firstWhere('id', $this->productId) : null;
        $bioLinks = PromoLink::where('seller_id', $sellerId)->where('label', 'like', 'Link in bio%')->get();

        return view('livewire.seller.social-studio', [
            'platforms' => PlatformRegistry::all(),
            'accounts' => $accounts,
            'posts' => $posts,
            'products' => $products,
            'autoPost' => collect(PlatformRegistry::all())->keys()->mapWithKeys(fn ($k) => [$k => PlatformRegistry::autoPostConfigured($k)]),
            'totals' => [
                'clicks' => $targets->sum(fn ($t) => (int) $t->promoLink?->clicks),
                'orders' => $targets->sum(fn ($t) => (int) $t->promoLink?->orders),
                'revenue' => $targets->sum(fn ($t) => (float) $t->promoLink?->revenue),
            ],
            'byPlatform' => $targets->groupBy('platform')->map(fn ($g) => [
                'posts' => $g->count(),
                'clicks' => $g->sum(fn ($t) => (int) $t->promoLink?->clicks),
                'orders' => $g->sum(fn ($t) => (int) $t->promoLink?->orders),
                'revenue' => $g->sum(fn ($t) => (float) $t->promoLink?->revenue),
            ])->sortByDesc('revenue'),
            'best' => $posts->map(fn ($p) => ['post' => $p, 'clicks' => $p->targets->sum(fn ($t) => (int) $t->promoLink?->clicks), 'revenue' => $p->targets->sum(fn ($t) => (float) $t->promoLink?->revenue)])
                ->filter(fn ($r) => $r['clicks'] > 0)->sortByDesc(fn ($r) => [$r['revenue'], $r['clicks']])->take(5)->values(),
            'calMonth' => $month,
            'calPosts' => $posts->groupBy(fn ($p) => ($p->scheduled_at ?? $p->published_at ?? $p->created_at)->format('Y-m-d')),
            'bestHour' => $toolkit->bestHour($sellerId),
            'hashtags' => $toolkit->hashtags($product),
            // For the image maker and app previews (all same-site images, so the browser can draw them).
            'designProducts' => $products->filter(fn ($p) => $p->getFirstMedia('images'))->map(fn ($p) => [
                'id' => $p->id, 'title' => $p->title, 'image' => route('seller.social.image', $p),
                'price' => '$'.number_format($p->effectivePrice(), 2),
                'was' => $p->effectivePrice() < (float) $p->price ? '$'.number_format((float) $p->price, 2) : null,
                'off' => $p->effectivePrice() < (float) $p->price && (float) $p->price > 0 ? (int) round((1 - $p->effectivePrice() / (float) $p->price) * 100) : null,
                'fits' => $p->fitments->first() ? trim($p->fitments->first()->year.' '.$p->fitments->first()->make?->name.' '.$p->fitments->first()->model?->name) : null,
            ])->values(),
            'reviews' => Review::whereHas('product', fn ($q) => $q->where('seller_id', $sellerId))->where('status', 'published')->where('rating', '>=', 4)
                ->with('user:id,name', 'product:id,title')->latest()->limit(20)->get()
                ->map(fn ($r) => ['id' => $r->id, 'rating' => (int) $r->rating, 'text' => Str::limit((string) $r->body, 220), 'name' => Str::before((string) $r->user?->name, ' ') ?: 'A customer', 'product' => $r->product?->title])->values(),
            'storeName' => $sp?->business_name ?? 'My store',
            'logo' => $sp?->logo_path ? route('seller.social.logo') : null,
            'brandColor' => \App\Services\Marketing\CampaignDesign::brand($sp?->email_brand)['color'],
            'bioUrl' => $sp?->slug ? route('bio.show', $sp->slug) : null,
            'bioStats' => ['views' => (int) $sp?->bio_views, 'clicks' => (int) $bioLinks->sum('clicks'), 'orders' => (int) $bioLinks->sum('orders'), 'revenue' => (float) $bioLinks->sum('revenue')],
            'designImage' => $this->design ? \App\Models\SocialDesign::where('seller_id', $sellerId)->find($this->design)?->imageUrl() : null,
            'myDesigns' => \App\Models\SocialDesign::where('seller_id', $sellerId)->latest('updated_at')->limit(8)->get(['id', 'name', 'image_path']),
            'channelPreviews' => collect($this->channelImages)->filter(fn ($f) => is_object($f) && method_exists($f, 'temporaryUrl'))->map(fn ($f) => $f->temporaryUrl())->all(),
            'coupons' => Coupon::where('seller_id', $sellerId)->whereNull('user_id')->where('is_active', true)->get(['id', 'code', 'type', 'value']),
        ]);
    }
}
