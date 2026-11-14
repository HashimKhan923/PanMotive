<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Services\Marketing\Social\MetaClient;
use App\Services\Marketing\Social\PlatformRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Connects a seller's own Facebook Page (and the Instagram account linked to it) through Meta.
 * One login covers both apps. Other apps are "coming soon" and use the share kit.
 */
class SocialConnectController extends Controller
{
    private const PAGES = 'meta_pages';

    public function connect(Request $request, string $platform)
    {
        abort_unless(PlatformRegistry::isValid($platform), 404);
        $back = redirect()->route('seller.social.index', ['tab' => 'accounts']);
        if (! PlatformRegistry::isActive($platform)) {
            return $back->with('error', PlatformRegistry::label($platform).' is coming soon. Use the share kit for now.');
        }
        if (! MetaClient::configured()) {
            return $back->with('error', 'Connecting Facebook and Instagram is being set up. Use the share kit for now.');
        }

        $state = Str::random(40);
        $request->session()->put('social_oauth.meta', $state);

        return redirect()->away(app(MetaClient::class)->dialogUrl($state, route('seller.social.callback', 'facebook')));
    }

    public function callback(Request $request, MetaClient $meta, string $platform)
    {
        abort_unless($platform === 'facebook' && MetaClient::configured(), 404);
        $back = redirect()->route('seller.social.index', ['tab' => 'accounts']);

        if (! $request->filled('state') || $request->session()->pull('social_oauth.meta') !== $request->state) {
            return $back->with('error', 'Connection failed. Please try again.');
        }
        if (! $request->filled('code')) {
            return $back->with('error', 'Connection was cancelled.');
        }

        try {
            $pages = $meta->pagesFromCode((string) $request->code, route('seller.social.callback', 'facebook'));
        } catch (\Throwable $e) {
            report($e);

            return $back->with('error', 'Facebook did not allow the connection. Please try again.');
        }

        if (! $pages) {
            return $back->with('error', 'No Facebook Page was found. You need a Facebook business Page you manage, and you must tick it when Facebook asks which Pages to share.');
        }
        if (count($pages) === 1) {
            return $this->finish($back, $meta, $pages[0]);
        }

        // More than one Page: the seller picks which one their posts go to.
        $request->session()->put(self::PAGES, Crypt::encrypt(['seller' => auth()->id(), 'at' => now()->timestamp, 'pages' => $pages]));

        return $back->with('success', 'Choose which Facebook Page your posts should go to.');
    }

    /** The seller chose one of their Pages. */
    public function choosePage(Request $request, MetaClient $meta)
    {
        $data = $request->validate(['page' => 'required|string|max:64']);
        $back = redirect()->route('seller.social.index', ['tab' => 'accounts']);
        $saved = self::pendingPages(true);
        $page = collect($saved)->firstWhere('id', $data['page']);
        if (! $page) {
            return $back->with('error', 'That choice expired. Please connect again.');
        }

        return $this->finish($back, $meta, $page);
    }

    public function disconnect(SocialAccount $socialAccount)
    {
        abort_unless($socialAccount->seller_id === auth()->id(), 403);
        $socialAccount->delete();

        return back()->with('success', PlatformRegistry::label($socialAccount->platform).' disconnected. Posts there will use the share kit again.');
    }

    /** Pages waiting for the seller to choose one (tokens never leave the server). */
    public static function pendingPages(bool $forget = false): array
    {
        $raw = $forget ? session()->pull(self::PAGES) : session(self::PAGES);
        try {
            $data = $raw ? Crypt::decrypt($raw) : null;
        } catch (\Throwable) {
            return [];
        }

        return $data && $data['seller'] === auth()->id() && $data['at'] > now()->subMinutes(30)->timestamp ? $data['pages'] : [];
    }

    private function finish($back, MetaClient $meta, array $page)
    {
        [, $ig] = $meta->connectPage(auth()->id(), $page);

        return $back->with('success', $ig
            ? 'Connected! Posts will go to your Facebook Page "'.$page['name'].'" and Instagram '.$ig->handle.'.'
            : 'Facebook Page "'.$page['name'].'" connected. No Instagram business account is linked to this Page, so Instagram still uses the share kit.');
    }
}
