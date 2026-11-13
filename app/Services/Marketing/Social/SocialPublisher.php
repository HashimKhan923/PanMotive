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

            $result = $this->send($post, $account, $target->link(), $target->caption);
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

    private function send(SocialPost $post, SocialAccount $account, ?string $link, ?string $caption = null): array
    {
        $endpoint = config("services.social.{$account->platform}.endpoint");

        try {
            $res = Http::withToken($account->access_token)->timeout(15)->post($endpoint, [
                'text' => $this->body($post, $account->platform, $link, $caption),
                'link' => $link,
                'media_url' => $post->media_path ? asset('storage/'.$post->media_path) : null,
            ]);

            return $res->successful()
                ? ['status' => 'published', 'external_id' => (string) $res->json('id'), 'permalink' => $res->json('permalink')]
                : ['status' => 'failed', 'error' => 'API '.$res->status().': '.Str::limit($res->body(), 120)];
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'error' => Str::limit($e->getMessage(), 150)];
        }
    }

    private function body(SocialPost $post, string $platform, ?string $link, ?string $caption = null): string
    {
        $limit = PlatformRegistry::config($platform)['char_limit'] ?? 2000;
        $text = trim($caption ?: $post->body); // each channel can have its own wording
        if ($link && ! str_contains($text, $link)) {
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
