<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Services\Marketing\Social\PlatformRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SocialConnectController extends Controller
{
    /**
     * Start OAuth for a platform whose app is configured. There is no demo/fake connection:
     * unconfigured platforms use the share kit in Social studio instead.
     */
    public function connect(Request $request, string $platform)
    {
        abort_unless(PlatformRegistry::isValid($platform), 404);

        if (! PlatformRegistry::autoPostConfigured($platform)) {
            return redirect()->route('seller.social.index', ['tab' => 'accounts'])
                ->with('error', 'Auto-posting to '.PlatformRegistry::label($platform).' isn\'t available yet — use the share kit for now.');
        }

        $state = Str::random(40);
        $request->session()->put("social_oauth.$platform", $state);

        return redirect()->away(config("services.social.{$platform}.auth_url").'?'.http_build_query([
            'client_id' => config("services.social.{$platform}.client_id"),
            'redirect_uri' => route('seller.social.callback', $platform),
            'response_type' => 'code',
            'scope' => config("services.social.{$platform}.scope", ''),
            'state' => $state,
        ]));
    }

    /** Standard OAuth 2 authorization-code exchange; nothing is stored unless the platform issues a token. */
    public function callback(Request $request, string $platform)
    {
        abort_unless(PlatformRegistry::isValid($platform) && PlatformRegistry::autoPostConfigured($platform), 404);

        $back = redirect()->route('seller.social.index', ['tab' => 'accounts']);

        if (! $request->filled('state') || $request->session()->pull("social_oauth.$platform") !== $request->state) {
            return $back->with('error', 'Connection failed — please try again.');
        }
        if (! $request->filled('code')) {
            return $back->with('error', 'Connection was cancelled.');
        }

        try {
            $res = Http::asForm()->timeout(15)->post(config("services.social.{$platform}.token_url"), [
                'grant_type' => 'authorization_code',
                'code' => $request->code,
                'redirect_uri' => route('seller.social.callback', $platform),
                'client_id' => config("services.social.{$platform}.client_id"),
                'client_secret' => config("services.social.{$platform}.client_secret"),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return $back->with('error', 'Couldn\'t reach '.PlatformRegistry::label($platform).'. Please try again.');
        }

        if (! $res->successful() || ! $res->json('access_token')) {
            return $back->with('error', PlatformRegistry::label($platform).' didn\'t authorise the connection.');
        }

        SocialAccount::updateOrCreate(
            ['seller_id' => auth()->id(), 'platform' => $platform],
            [
                'handle' => $res->json('username') ?? $res->json('name') ?? auth()->user()->sellerProfile?->business_name,
                'access_token' => $res->json('access_token'),
                'refresh_token' => $res->json('refresh_token'),
                'token_expires_at' => $res->json('expires_in') ? now()->addSeconds((int) $res->json('expires_in')) : null,
                'status' => 'connected',
                'meta' => ['mode' => 'live'],
            ]
        );

        return $back->with('success', PlatformRegistry::label($platform).' connected — posts there will go out automatically.');
    }

    public function disconnect(SocialAccount $socialAccount)
    {
        abort_unless($socialAccount->seller_id === auth()->id(), 403);
        $socialAccount->delete();

        return back()->with('success', 'Disconnected.');
    }
}
