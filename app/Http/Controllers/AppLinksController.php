<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * Lets panmotive.com links open in the shopping app when it's installed (iOS Universal Links and
 * Android App Links). Each store needs proof the website and the app belong together; until the app's
 * ids are set in .env these answer 404, so nothing changes for the website.
 */
class AppLinksController extends Controller
{
    /** Paths the app can show. Everything else (seller portal, admin, checkout pages…) stays on the web. */
    private const PATHS = ['/', '/products/*', '/sellers/*', '/shop', '/shop/*', '/deals', '/plus', '/find-parts', '/fit-guarantee', '/orders', '/orders/*', '/wishlist', '/cart'];

    public function apple(): JsonResponse
    {
        $team = config('services.apple.team_id');
        $bundles = array_filter(array_map('trim', explode(',', (string) config('services.apple.client_ids'))));
        abort_unless($team && $bundles, 404);

        return response()->json([
            'applinks' => [
                'details' => [[
                    'appIDs' => array_map(fn ($b) => "{$team}.{$b}", $bundles),
                    'components' => array_map(fn ($p) => ['/' => $p], self::PATHS),
                ]],
            ],
            'webcredentials' => ['apps' => array_map(fn ($b) => "{$team}.{$b}", $bundles)],
        ]);
    }

    public function android(): JsonResponse
    {
        $package = config('services.android.package');
        $prints = array_filter(array_map('trim', explode(',', (string) config('services.android.cert_sha256'))));
        abort_unless($package && $prints, 404);

        return response()->json([[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => ['namespace' => 'android_app', 'package_name' => $package, 'sha256_cert_fingerprints' => array_values($prints)],
        ]]);
    }
}
