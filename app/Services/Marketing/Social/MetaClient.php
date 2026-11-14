<?php

namespace App\Services\Marketing\Social;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Facebook Pages + Instagram (professional accounts linked to a Page) through Meta's Graph API.
 *
 * Connect: Facebook Login (for Business when META_CONFIG_ID is set) → long-lived user token →
 * the seller's Pages with their (non-expiring) Page tokens and linked Instagram account.
 * Post: Facebook photo (uploaded directly) or text/link post; Instagram needs a public JPEG
 * between 4:5 and 1.91:1, so the image is converted and padded to fit first.
 */
class MetaClient
{
    public const PLATFORMS = ['facebook', 'instagram'];

    /** Shown to sellers in the connect checklist and used for Facebook Login without a config id. */
    public const SCOPES = ['pages_show_list', 'pages_manage_posts', 'pages_read_engagement', 'instagram_basic', 'instagram_content_publish', 'business_management'];

    public static function configured(): bool
    {
        return filled(config('services.meta.app_id')) && filled(config('services.meta.app_secret'));
    }

    private function graph(string $path = ''): string
    {
        return 'https://graph.facebook.com/'.config('services.meta.graph_version', 'v27.0').'/'.ltrim($path, '/');
    }

    public function dialogUrl(string $state, string $redirect): string
    {
        $q = ['client_id' => config('services.meta.app_id'), 'redirect_uri' => $redirect, 'state' => $state, 'response_type' => 'code'];
        filled(config('services.meta.config_id')) ? $q['config_id'] = config('services.meta.config_id') : $q['scope'] = implode(',', self::SCOPES);

        return 'https://www.facebook.com/'.config('services.meta.graph_version', 'v27.0').'/dialog/oauth?'.http_build_query($q);
    }

    /**
     * Code → long-lived user token → the Pages this person manages.
     *
     * @return array<int, array{id:string, name:string, token:string, picture:?string, ig_id:?string, ig_username:?string}>
     */
    public function pagesFromCode(string $code, string $redirect): array
    {
        $short = Http::timeout(20)->get($this->graph('oauth/access_token'), [
            'client_id' => config('services.meta.app_id'), 'client_secret' => config('services.meta.app_secret'),
            'redirect_uri' => $redirect, 'code' => $code,
        ])->throw()->json('access_token');
        abort_unless($short, 502);

        $long = Http::timeout(20)->get($this->graph('oauth/access_token'), [
            'grant_type' => 'fb_exchange_token', 'client_id' => config('services.meta.app_id'),
            'client_secret' => config('services.meta.app_secret'), 'fb_exchange_token' => $short,
        ])->json('access_token') ?: $short;

        $pages = [];
        $url = $this->graph('me/accounts');
        $params = ['fields' => 'id,name,access_token,picture{url},instagram_business_account{id,username}', 'limit' => 100, 'access_token' => $long];
        for ($i = 0; $url && $i < 5; $i++) {
            $res = Http::timeout(20)->get($url, $params)->throw();
            foreach ((array) $res->json('data') as $p) {
                if (empty($p['id']) || empty($p['access_token'])) {
                    continue;
                }
                $pages[] = [
                    'id' => (string) $p['id'], 'name' => (string) ($p['name'] ?? 'Facebook Page'), 'token' => (string) $p['access_token'],
                    'picture' => $p['picture']['data']['url'] ?? null,
                    'ig_id' => isset($p['instagram_business_account']['id']) ? (string) $p['instagram_business_account']['id'] : null,
                    'ig_username' => $p['instagram_business_account']['username'] ?? null,
                ];
            }
            $url = $res->json('paging.next');
            $params = [];
        }

        return $pages;
    }

    /** Save the chosen Page (and its Instagram, if linked) as this seller's connected accounts. */
    public function connectPage(int $sellerId, array $page): array
    {
        $fb = SocialAccount::updateOrCreate(['seller_id' => $sellerId, 'platform' => 'facebook'], [
            'handle' => $page['name'], 'external_id' => $page['id'], 'access_token' => $page['token'],
            'refresh_token' => null, 'token_expires_at' => null, 'status' => 'connected',
            'meta' => ['mode' => 'live', 'page_id' => $page['id'], 'picture' => $page['picture'] ?? null],
        ]);

        $ig = null;
        if (! empty($page['ig_id'])) {
            $ig = SocialAccount::updateOrCreate(['seller_id' => $sellerId, 'platform' => 'instagram'], [
                'handle' => $page['ig_username'] ? '@'.$page['ig_username'] : $page['name'].' (Instagram)',
                'external_id' => $page['ig_id'], 'access_token' => $page['token'],
                'refresh_token' => null, 'token_expires_at' => null, 'status' => 'connected',
                'meta' => ['mode' => 'live', 'page_id' => $page['id'], 'ig_user_id' => $page['ig_id']],
            ]);
        } else {
            SocialAccount::where('seller_id', $sellerId)->where('platform', 'instagram')->delete();
        }

        return [$fb, $ig];
    }

    /**
     * Post to a connected Page or Instagram account.
     *
     * @param  string|null  $image  absolute path of the image on this server
     * @return array{status:string, external_id?:string, permalink?:?string, error?:string}
     */
    public function publish(SocialAccount $account, string $text, ?string $link, ?string $image): array
    {
        try {
            return $account->platform === 'instagram'
                ? $this->postInstagram($account, $text, $image)
                : $this->postFacebook($account, $text, $link, $image);
        } catch (\Illuminate\Http\Client\RequestException $e) {
            return $this->failure($account, $e->response);
        } catch (\Throwable $e) {
            report($e);

            return ['status' => 'failed', 'error' => 'Could not reach '.PlatformRegistry::label($account->platform).'. Try again.'];
        }
    }

    private function postFacebook(SocialAccount $account, string $text, ?string $link, ?string $image): array
    {
        $page = $account->meta['page_id'] ?? $account->external_id;
        if ($image && is_file($image)) {
            $res = Http::timeout(60)->attach('source', file_get_contents($image), basename($image))
                ->post($this->graph($page.'/photos'), ['caption' => $text, 'access_token' => $account->access_token])->throw();
            $postId = (string) ($res->json('post_id') ?: $res->json('id'));
        } else {
            $res = Http::asForm()->timeout(30)->post($this->graph($page.'/feed'), array_filter([
                'message' => $text, 'link' => $link, 'access_token' => $account->access_token,
            ]))->throw();
            $postId = (string) $res->json('id');
        }

        return ['status' => 'published', 'external_id' => $postId, 'permalink' => $postId ? 'https://www.facebook.com/'.$postId : null];
    }

    private function postInstagram(SocialAccount $account, string $text, ?string $image): array
    {
        if (! $image || ! is_file($image)) {
            return ['status' => 'failed', 'error' => 'Instagram needs an image. Add a photo or a design to this post.'];
        }
        $ig = $account->meta['ig_user_id'] ?? $account->external_id;
        $jpeg = $this->instagramJpeg($image, $account->seller_id);

        $container = Http::asForm()->timeout(60)->post($this->graph($ig.'/media'), [
            'image_url' => Storage::disk('public')->url($jpeg), 'caption' => $text, 'access_token' => $account->access_token,
        ])->throw()->json('id');

        $mediaId = null;
        for ($try = 0; $try < 4 && ! $mediaId; $try++) {
            if ($try) {
                sleep(3); // the container is still processing
            }
            $res = Http::asForm()->timeout(60)->post($this->graph($ig.'/media_publish'), ['creation_id' => $container, 'access_token' => $account->access_token]);
            if ($res->successful()) {
                $mediaId = (string) $res->json('id');
            } elseif ((int) $res->json('error.code') !== 9007 && (int) $res->json('error.error_subcode') !== 2207027) {
                $res->throw();
            }
        }
        if (! $mediaId) {
            return ['status' => 'failed', 'error' => 'Instagram is still processing the image. Press Retry in a minute.'];
        }
        $permalink = Http::timeout(20)->get($this->graph($mediaId), ['fields' => 'permalink', 'access_token' => $account->access_token])->json('permalink');

        return ['status' => 'published', 'external_id' => $mediaId, 'permalink' => $permalink];
    }

    /**
     * Instagram takes only JPEG, 320–1440 px wide, between 4:5 (tall) and 1.91:1 (wide).
     * Taller or wider images are padded (never cropped, so text in a design is never cut off).
     */
    public function instagramJpeg(string $source, int $sellerId): string
    {
        $src = @imagecreatefromstring((string) file_get_contents($source));
        abort_unless($src !== false, 422, 'The image could not be read.');
        [$w, $h] = [imagesx($src), imagesy($src)];

        [$cw, $ch] = [$w, $h];
        if ($w / $h < 0.8) {
            $cw = (int) ceil($h * 0.8);
        } elseif ($w / $h > 1.91) {
            $ch = (int) ceil($w / 1.91);
        }
        $scale = min(1, 1440 / $cw);
        $scale = max($scale, 320 / $cw);
        [$ow, $oh] = [(int) round($cw * $scale), (int) round($ch * $scale)];

        $out = imagecreatetruecolor($ow, $oh);
        $bg = imagecolorat($src, 0, 0);
        $rgb = imagecolorsforindex($src, $bg);
        imagefill($out, 0, 0, imagecolorallocate($out, $rgb['red'], $rgb['green'], $rgb['blue']));
        $dw = (int) round($w * $scale);
        $dh = (int) round($h * $scale);
        imagecopyresampled($out, $src, (int) (($ow - $dw) / 2), (int) (($oh - $dh) / 2), 0, 0, $dw, $dh, $w, $h);

        // Meta downloads the file when the post is made; copies older than a day are no longer needed.
        $disk = Storage::disk('public');
        foreach ($disk->files('social/'.$sellerId.'/ig') as $old) {
            if ($disk->lastModified($old) < now()->subDay()->getTimestamp()) {
                $disk->delete($old);
            }
        }
        $path = 'social/'.$sellerId.'/ig/'.Str::lower(Str::random(32)).'.jpg';
        $disk->makeDirectory(dirname($path));
        imagejpeg($out, Storage::disk('public')->path($path), 90);
        imagedestroy($src);
        imagedestroy($out);

        return $path;
    }

    private function failure(SocialAccount $account, $response): array
    {
        $code = (int) $response?->json('error.code');
        $msg = (string) ($response?->json('error.error_user_msg') ?: $response?->json('error.message') ?: 'Unknown error');
        if (in_array($code, [190, 102], true) || (int) $response?->json('error.error_subcode') === 463) {
            $account->update(['status' => 'expired']);

            return ['status' => 'failed', 'error' => 'Your '.PlatformRegistry::label($account->platform).' connection expired. Reconnect it in Auto-posting, then press Retry.'];
        }
        if (in_array($code, [10, 200], true)) {
            return ['status' => 'failed', 'error' => 'Panmotive is not allowed to post to this '.PlatformRegistry::label($account->platform).' account. Reconnect and allow posting.'];
        }

        return ['status' => 'failed', 'error' => PlatformRegistry::label($account->platform).' said: '.Str::limit($msg, 140)];
    }
}
