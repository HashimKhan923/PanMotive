<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\SocialAccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * "Continue with Google" for shoppers. Signs in an existing account with the same (Google-verified)
 * email, or creates a new shopper account. Staff accounts must use their password.
 */
class GoogleAuthController extends Controller
{
    public static function configured(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    /** Only for shoppers on the shop's own address — never on the seller portal or admin console. */
    public static function availableHere(): bool
    {
        return self::configured() && \App\Support\Portal::isShop();
    }

    public function redirect(Request $request)
    {
        abort_unless(self::availableHere(), 404);

        // Where to go afterwards (e.g. back to checkout) — only addresses on our own site.
        $back = (string) $request->query('redirect', '');
        if ($back !== '' && parse_url($back, PHP_URL_HOST) === $request->getHost()) {
            $request->session()->put('url.intended', $back);
        }

        $state = Str::random(40);
        $request->session()->put('google_oauth_state', $state);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => route('auth.google.callback'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
        ]));
    }

    public function callback(Request $request)
    {
        abort_unless(self::availableHere(), 404);
        $fail = fn (string $msg) => redirect()->route('login')->withErrors(['form.email' => $msg]);

        if (! $request->filled('state') || $request->session()->pull('google_oauth_state') !== $request->state) {
            return $fail('Google sign-in expired. Please try again.');
        }
        if (! $request->filled('code')) {
            return $fail('Google sign-in was cancelled.');
        }

        try {
            $token = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
                'code' => $request->code,
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'redirect_uri' => route('auth.google.callback'),
                'grant_type' => 'authorization_code',
            ])->throw()->json('access_token');
            $google = Http::withToken((string) $token)->timeout(15)->get('https://openidconnect.googleapis.com/v1/userinfo')->throw()->json();
        } catch (\Throwable $e) {
            report($e);

            return $fail('We could not reach Google. Please try again.');
        }

        $user = app(SocialAccountService::class)->resolve(
            'google', (string) ($google['sub'] ?? ''), $google['email'] ?? null,
            ($google['email_verified'] ?? false) === true, $google['name'] ?? null,
        );
        if (is_string($user)) {
            return $fail($user);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
