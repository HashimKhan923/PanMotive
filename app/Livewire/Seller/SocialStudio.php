<?php

namespace App\Livewire\Seller;

use App\Jobs\PublishSocialPost;
use App\Models\Product;
use App\Models\PromoLink;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Services\Marketing\MarketingCopyService;
use App\Services\Marketing\Social\PlatformRegistry;
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
 */
#[Layout('layouts.seller')]
class SocialStudio extends Component
{
    use WithFileUploads;

    #[Url]
    public string $tab = 'compose';

    // compose
    public string $body = '';
    public ?int $productId = null;
    public array $channels = ['facebook'];
    public $media;
    public string $when = 'now';
    public ?string $scheduledAt = null;
    public string $tone = 'friendly';

    public function generate(MarketingCopyService $copy): void
    {
        $platform = $this->channels[0] ?? 'facebook';
        $product = $this->productId ? Product::where('seller_id', auth()->id())->find($this->productId) : null;
        $product?->loadMissing('brand', 'category');

        $this->body = $copy->socialCaption($product, $platform, $this->tone);
        $this->dispatch('toast', message: 'Caption drafted — tweak and post.');
    }

    public function publish(): void
    {
        abort_unless(SellerContext::can('marketing'), 403);
        $this->validate([
            'body' => 'required|string|max:5000',
            'channels' => 'required|array|min:1',
            'channels.*' => 'in:'.implode(',', array_keys(PlatformRegistry::all())),
            'media' => 'nullable|image|max:8192',
            'scheduledAt' => 'nullable|date|after:now',
        ], ['channels.required' => 'Pick at least one channel.']);

        $product = $this->productId ? Product::where('seller_id', auth()->id())->find($this->productId) : null;
        $path = $this->media ? $this->media->store('social/'.auth()->id(), 'public') : null;
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
            $post->targets()->create([
                'social_account_id' => $accounts[$platform] ?? null,
                'promo_link_id' => $link->id,
                'platform' => $platform,
                'status' => 'pending',
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

        $this->reset('body', 'productId', 'media', 'when', 'scheduledAt');
        $this->tab = 'posts';
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

    public function render()
    {
        $accounts = SocialAccount::where('seller_id', auth()->id())->get()->keyBy('platform');

        $posts = SocialPost::where('seller_id', auth()->id())
            ->with('targets.promoLink', 'product:id,title,slug')
            ->latest('scheduled_at')->latest()
            ->limit(60)->get();

        return view('livewire.seller.social-studio', [
            'platforms' => PlatformRegistry::all(),
            'accounts' => $accounts,
            'posts' => $posts,
            'products' => Product::where('seller_id', auth()->id())->where('status', 'active')->orderBy('title')->get(['id', 'title']),
            'autoPost' => collect(PlatformRegistry::all())->keys()->mapWithKeys(fn ($k) => [$k => PlatformRegistry::autoPostConfigured($k)]),
            'totals' => [
                'clicks' => $posts->flatMap->targets->sum(fn ($t) => (int) $t->promoLink?->clicks),
                'orders' => $posts->flatMap->targets->sum(fn ($t) => (int) $t->promoLink?->orders),
                'revenue' => $posts->flatMap->targets->sum(fn ($t) => (float) $t->promoLink?->revenue),
            ],
        ]);
    }
}
