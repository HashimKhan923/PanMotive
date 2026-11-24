<?php

namespace Tests\Feature\Storefront;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopHostTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_pages_on_the_portal_addresses_forward_to_the_shop(): void
    {
        $domain = config('app.domain');

        foreach (['sell', 'admin'] as $portal) {
            $res = $this->get("http://{$portal}.{$domain}/deals?page=2");
            $res->assertStatus(301);
            $to = $res->headers->get('Location');
            $this->assertStringEndsWith('/deals?page=2', $to);
            $this->assertNotSame("{$portal}.{$domain}", parse_url($to, PHP_URL_HOST));
        }

        $this->get("http://sell.{$domain}/cart", ['Accept' => 'text/html'])->assertStatus(301);
    }

    public function test_sign_in_and_sign_out_still_work_on_the_portal_addresses(): void
    {
        $domain = config('app.domain');

        $this->get("http://sell.{$domain}/login")->assertOk();
        $this->get("http://admin.{$domain}/login")->assertOk();
        $this->get("http://sell.{$domain}/forgot-password")->assertOk();

        $this->actingAs(\App\Models\User::factory()->create())
            ->post("http://sell.{$domain}/logout")->assertRedirect();
        $this->assertGuest();
    }

    public function test_the_shop_itself_and_the_portal_logins_still_work(): void
    {
        $this->get(route('deals.index'))->assertOk();
        $this->get(route('seller.dashboard'))->assertRedirect();   // seller portal still answers on its own address
    }
}
