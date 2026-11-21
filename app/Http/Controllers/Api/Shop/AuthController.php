<?php

namespace App\Http\Controllers\Api\Shop;

use App\Http\Controllers\Controller;
use App\Models\AppPushToken;
use App\Models\User;
use App\Services\Auth\IdTokenVerifier;
use App\Services\Auth\SocialAccountService;
use App\Services\Cart\CartService;
use App\Services\Cart\WishlistService;
use App\Services\Marketing\ReferralService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

/**
 * Signing in to the shopping app. Every way in returns the same thing: a token the app keeps in the
 * phone's secure storage, and the shopper's profile. Whatever the shopper put in the cart or wishlist
 * as a guest on this phone moves into their account.
 */
class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'string', Rules\Password::defaults()],
            'ref' => ['nullable', 'string', 'max:64'],
        ], ['email.unique' => 'An account with this email already exists. Sign in instead.']);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);
        event(new Registered($user));

        if (! empty($data['ref'])) {
            app(ReferralService::class)->trackSignup($data['ref'], $user);
        }

        return $this->signedIn($request, $user, 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = 'app-login:'.Str::transliterate(Str::lower($data['email']).'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many sign-in attempts. Please try again in '.ceil(RateLimiter::availableIn($key) / 60).' minute(s).',
            ]);
        }

        if (! Auth::guard('web')->validate(['email' => $data['email'], 'password' => $data['password']])) {
            RateLimiter::hit($key);
            throw ValidationException::withMessages(['email' => trans('auth.failed')]);
        }
        RateLimiter::clear($key);

        $user = User::whereRaw('LOWER(email) = ?', [Str::lower($data['email'])])->firstOrFail();
        if ($user->is_active === false) {
            throw ValidationException::withMessages(['email' => 'This account has been suspended. Contact support if you think this is a mistake.']);
        }

        return $this->signedIn($request, $user);
    }

    /** Native "Sign in with Google / Apple" on the phone sends us the provider's ID token. */
    public function social(Request $request, string $provider, IdTokenVerifier $verifier, SocialAccountService $accounts): JsonResponse
    {
        abort_unless(in_array($provider, ['google', 'apple'], true), 404);
        $data = $request->validate([
            'id_token' => ['required', 'string', 'max:8000'],
            'nonce' => ['nullable', 'string', 'max:200'],
            // Apple only shares the name on the very first sign-in, in the app, outside the token.
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        if (! $verifier->configured($provider)) {
            return response()->json(['message' => ucfirst($provider).' sign-in isn\'t available yet.', 'code' => 'unavailable'], 422);
        }

        $claims = $verifier->verify($provider, $data['id_token'], $data['nonce'] ?? null);
        if (! $claims) {
            return response()->json(['message' => 'We couldn\'t verify that sign-in. Please try again.', 'code' => 'invalid_token'], 422);
        }

        $user = $accounts->resolve($provider, $claims['sub'], $claims['email'], $claims['email_verified'], $data['name'] ?? $claims['name']);
        if (is_string($user)) {
            return response()->json(['message' => $user, 'code' => 'refused'], 422);
        }

        return $this->signedIn($request, $user, $user->wasRecentlyCreated ? 201 : 200);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        // Same answer whether or not the email has an account, so the app can't be used to probe for accounts.
        Password::sendResetLink($request->only('email'));

        return response()->json(['message' => 'If an account exists for that email, we\'ve sent a link to reset the password.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => self::profile($request->user())]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
        ]);
        $user->forceFill($data)->save();

        return response()->json(['user' => self::profile($user->fresh())]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->validate([
            'current_password' => ['required', 'string', 'current_password:sanctum'],
            'password' => ['required', 'string', Rules\Password::defaults(), 'different:current_password'],
        ]);
        $user->forceFill(['password' => Hash::make($request->password)])->save();
        // Sign out every other phone.
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return response()->json(['message' => 'Password changed.']);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        if ($pushToken = $request->input('push_token')) {
            AppPushToken::where('user_id', $request->user()->id)->where('token', $pushToken)->delete();
        }
        $token?->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    /**
     * Delete the account from inside the app (an App Store requirement). People who signed up with
     * Google or Apple never chose a password, so they confirm by typing DELETE instead.
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        $social = filled($user->google_id) || filled($user->apple_id);

        $request->validate([
            'password' => [$social ? 'nullable' : 'required', 'string'],
            'confirm' => [$social ? 'required_without:password' : 'nullable', 'nullable', 'string'],
        ]);

        $ok = ($request->filled('password') && Hash::check((string) $request->password, $user->password))
            || ($social && strtoupper(trim((string) $request->confirm)) === 'DELETE');
        if (! $ok) {
            throw ValidationException::withMessages([
                $request->filled('password') ? 'password' : 'confirm' => $request->filled('password')
                    ? 'That password isn\'t right.'
                    : 'Type DELETE to confirm.',
            ]);
        }
        if ($user->hasAnyRole(SocialAccountService::STAFF) || $user->sellerProfile) {
            return response()->json([
                'message' => 'This account also runs a store or staff access. Please delete it from the website or contact support.',
                'code' => 'not_shopper_only',
            ], 422);
        }

        $user->tokens()->delete();
        AppPushToken::where('user_id', $user->id)->delete();
        $user->delete();

        return response()->json(['message' => 'Your account has been deleted.']);
    }

    /** @return array<string, mixed> */
    public static function profile(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'email_verified' => $user->email_verified_at !== null,
            'linked' => array_values(array_filter([filled($user->google_id) ? 'google' : null, filled($user->apple_id) ? 'apple' : null])),
            'garage' => $user->active_vehicle,
            'plus' => $user->isPlusMember(),
            'is_seller' => (bool) $user->sellerProfile,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    private function signedIn(Request $request, User $user, int $status = 200): JsonResponse
    {
        // Move this phone's guest cart and wishlist into the account (the services merge on first use).
        Auth::setUser($user);
        app(CartService::class)->currentCart();
        app(WishlistService::class)->current();

        $device = Str::limit(trim((string) $request->input('device_name', $request->userAgent() ?: 'Panmotive app')), 120, '');
        $token = $user->createToken($device, ['shop'])->plainTextToken;

        return response()->json(['token' => $token, 'user' => self::profile($user)], $status);
    }
}
