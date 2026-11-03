<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use PragmaRX\Google2FAQRCode\Google2FA as Google2FAQRCode;

class TwoFactorController extends Controller
{
    public function __construct(private Google2FA $google2fa) {}

    // ---- Enrolment (authenticated) --------------------------------------

    public function setup(Request $request)
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return view('auth.two-factor.manage');
        }

        if (! $request->session()->has('2fa.pending_secret')) {
            $request->session()->put('2fa.pending_secret', $this->google2fa->generateSecretKey());
        }

        $secret = $request->session()->get('2fa.pending_secret');
        $qrUrl = $this->google2fa->getQRCodeUrl(config('app.name'), $user->email, $secret);
        $qrImage = (new Google2FAQRCode)->getQRCodeInline(config('app.name'), $user->email, $secret);

        return view('auth.two-factor.setup', compact('secret', 'qrUrl', 'qrImage'));
    }

    public function confirm(Request $request)
    {
        $request->validate(['code' => 'required|string']);

        $secret = $request->session()->get('2fa.pending_secret');

        if (! $secret || ! $this->google2fa->verifyKey($secret, $request->code)) {
            return back()->withErrors(['code' => 'That code is not valid. Try again.']);
        }

        $recovery = collect(range(1, 8))->map(fn () => Str::random(10).'-'.Str::random(10))->all();

        $request->user()->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $recovery,
            'two_factor_confirmed_at' => now(),
        ])->save();

        $request->session()->forget('2fa.pending_secret');

        return redirect()->route('two-factor.setup')
            ->with('recovery_codes', $recovery)
            ->with('success', 'Two-factor authentication is now on.');
    }

    public function disable(Request $request)
    {
        $request->validate(['password' => 'required|current_password']);

        $request->user()->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return back()->with('success', 'Two-factor authentication disabled.');
    }

    // ---- Per-login challenge (pre-authentication) ----------------------

    public function challenge(Request $request)
    {
        if (! $request->session()->has('2fa.user')) {
            return redirect()->route('login');
        }

        return view('auth.two-factor.challenge');
    }

    public function verifyChallenge(Request $request)
    {
        $request->validate(['code' => 'required|string']);

        $userId = $request->session()->get('2fa.user');
        $remember = (bool) $request->session()->get('2fa.remember', false);
        $user = $userId ? User::find($userId) : null;

        if (! $user || ! $user->hasTwoFactorEnabled()) {
            $request->session()->forget(['2fa.user', '2fa.remember']);

            return redirect()->route('login');
        }

        $code = trim($request->code);
        $ok = $this->google2fa->verifyKey($user->two_factor_secret, $code);

        if (! $ok) {
            $codes = $user->two_factor_recovery_codes ?? [];
            if (in_array($code, $codes, true)) {
                $user->forceFill(['two_factor_recovery_codes' => array_values(array_diff($codes, [$code]))])->save();
                $ok = true;
            }
        }

        if (! $ok) {
            return back()->withErrors(['code' => 'Invalid authentication or recovery code.']);
        }

        Auth::login($user, $remember);
        $request->session()->regenerate();
        $request->session()->forget(['2fa.user', '2fa.remember']);

        $isStaff = $user->hasAnyRole(['super_admin', 'support_agent', 'finance', 'catalog_manager']);

        return redirect()->intended($isStaff ? route('admin.dashboard') : route('dashboard'));
    }
}
