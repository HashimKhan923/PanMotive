<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

class PortalLoginPagesTest extends TestCase
{
    public function test_admin_login_has_no_sign_up(): void
    {
        $domain = config('app.domain');

        $this->get('http://admin.'.$domain.'/login')->assertOk()
            ->assertSee('Admin console')->assertSee('Staff only')
            ->assertDontSee('Create an account')->assertDontSee('cashback');

        $this->get('http://admin.'.$domain.'/register')->assertNotFound();
    }

    public function test_seller_login_speaks_to_sellers(): void
    {
        $domain = config('app.domain');

        $this->get('http://sell.'.$domain.'/login')->assertOk()
            ->assertSee('Seller login')->assertSee('New seller?')->assertSee('Create an account');

        $this->get('http://sell.'.$domain.'/register')->assertOk()->assertSee('Create your seller account');
    }

    public function test_shop_login_is_unchanged(): void
    {
        $this->get('/login')->assertOk()->assertSee('Welcome back')->assertSee('New to Panmotive?');
        $this->get('/register')->assertOk()->assertSee('Create your account');
    }
}
