<?php

namespace App\Services\Marketing\Social;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Publishes a SocialPost to each of its target channels. A platform's API is called only when
 * the seller connected a real account (OAuth) AND that platform's app is configured. Otherwise
 * the target becomes a "share kit" — caption, image, tracked link and share button ready for the
 * seller to post themselves. Nothing is ever marked published that wasn't.
 */
class SocialPublisher
{
    public function publish(SocialPost $post): SocialPost
    {
        $post->update(['status' => 'publishing']);

        foreach ($post->targets()->where('status', 'pending')->get() as $target) {
            $account = $target->account ?: SocialAccount::where('seller_id', $post->seller_id)
                ->where('platform', $target->platform)->first();

            if (! $account?->isLive() || ! PlatformRegistry::autoPostConfigured($target->platform)) {
                $target->update(['status' => 'share_kit', 'error' => null]);

                continue;
            }

            $result = app(MetaClient::class)->publish(
                $account,
                $this->body($post, $target->platform, $target->link(), $target->caption),
                $target->link(),
                $this->imagePath($post, $target->media_path),
            );
            $target->update([
                'social_account_id' => $account->id,
                'status' => $result['status'],
                'external_id' => $result['external_id'] ?? null,
                'permalink' => $result['permalink'] ?? null,
                'error' => $result['error'] ?? null,
                'published_at' => $result['status'] === 'published' ? now() : null,
                'metrics' => null,
            ]);
        }

        $post->refresh()->recomputeStatus();

        return $post;
    }

    /** The post's image on this server: this app's own image, the post image, else the listing photo. */
    private function imagePath(SocialPost $post, ?string $own): ?string
    {
        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        foreach (array_filter([$own, $post->media_path]) as $rel) {
            if ($disk->exists($rel)) {
                return $disk->path($rel);
            }
        }
        $media = $post->product?->getFirstMedia('images');

        return $media && is_file($media->getPath()) ? $media->getPath() : null;
    }

    private function body(SocialPost $post, string $platform, ?string $link, ?string $caption = null): string
    {
        $limit = PlatformRegistry::config($platform)['char_limit'] ?? 2000;
        $text = trim($caption ?: $post->body); // each channel can have its own wording
        if ($platform === 'instagram') {
            // Instagram never makes caption links clickable: point people to the link in bio.
            $text = trim(str_replace((string) $link, '', $text))."\n\nShop this part: link in bio.";
        } elseif ($link && ! str_contains($text, $link)) {
            $text .= "\n".$link;
        }

        return Str::limit($text, $limit, '');
    }

    /**
     * Platform engagement (likes, reach) needs each platform's insights API, which isn't wired up
     * yet — so nothing is reported rather than inventing numbers. Clicks and orders come from the
     * post's tracked link instead (see SocialPostTarget::promoLink).
     */
    public function refreshMetrics(): int
    {
        return 0;
    }
}
