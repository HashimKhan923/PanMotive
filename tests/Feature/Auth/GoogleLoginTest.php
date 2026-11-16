<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/** "Continue with Google" for shoppers. */
class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.google', ['client_id' => 'gid.apps.googleusercontent.com', 'client_secret' => 'gsecret']);
    }

    private array $profile = [];

    private function google(array $profile): \Illuminate\Testing\TestResponse
    {
        $this->profile = $profile + ['sub' => 'g-123', 'email_verified' => true];
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'g-token', 'id_token' => 'x']),
            'openidconnect.googleapis.com/v1/userinfo' => fn () => Http::response($this->profile),
        ]);
        $go = $this->get(route('auth.google', ['redirect' => url('/checkout')]));
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $go->headers->get('Location'));
        $this->assertStringContainsString(urlencode(route('auth.google.callback')), $go->headers->get('Location'));

        return $this->get(route('auth.google.callback', ['code' => 'c', 'state' => session('google_oauth_state')]));
    }

    public function test_the_button_shows_only_when_google_is_set_up(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Continue with Google');
        $this->get(route('register'))->assertOk()->assertSee('Sign up with Google');

        // never on the seller portal or admin console
        $domain = config('app.domain');
        $this->get('http://sell.'.$domain.'/login')->assertOk()->assertDontSee('Continue with Google');
        $this->get('http://admin.'.$domain.'/login')->assertOk()->assertDontSee('Continue with Google');
        $this->get('http://sell.'.$domain.'/auth/google')->assertNotFound();
        $this->get('http://admin.'.$domain.'/auth/google/callback?code=x&state=y')->assertNotFound();

        config()->set('services.google.client_id', null);
        $this->get(route('login'))->assertOk()->assertDontSee('Continue with Google');
        $this->get(route('auth.google'))->assertNotFound();
    }

    public function test_a_new_shopper_gets_an_account_and_returns_to_checkout(): void
    {
        $this->google(['email' => 'Jane.Doe@Gmail.com', 'name' => 'Jane Doe'])->assertRedirect(url('/checkout'));

        $user = User::firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(['jane.doe@gmail.com', 'Jane Doe', 'g-123'], [$user->email, $user->name, $user->google_id]);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_an_existing_account_with_the_same_email_is_linked_not_duplicated(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com', 'email_verified_at' => null]);

        $this->google(['email' => 'JANE@example.com', 'name' => 'Jane'])->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame('g-123', $user->fresh()->google_id);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_unverified_emails_staff_and_suspended_accounts_are_refused(): void
    {
        $this->google(['email' => 'x@example.com', 'email_verified' => false])->assertRedirect(route('login'))->assertSessionHasErrors('form.email');
        $this->assertGuest();

        $this->seed(RoleSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        User::factory()->create(['email' => 'boss@example.com'])->assignRole('super_admin');
        $this->google(['email' => 'boss@example.com'])->assertSessionHasErrors(['form.email' => 'Staff accounts sign in with their email and password.']);
        $this->assertGuest();

        User::factory()->create(['email' => 'gone@example.com', 'is_active' => false]);
        $this->google(['email' => 'gone@example.com', 'sub' => 'g-999'])->assertSessionHasErrors('form.email');
        $this->assertGuest();
    }

    public function test_a_forged_state_is_refused_and_outside_redirects_are_ignored(): void
    {
        $this->get(route('auth.google.callback', ['code' => 'c', 'state' => 'forged']))->assertSessionHasErrors('form.email');
        $this->assertGuest();

        $this->get(route('auth.google', ['redirect' => 'https://evil.example/steal']));
        $this->assertNull(session('url.intended'));
    }
}
