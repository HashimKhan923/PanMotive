<?php

namespace Tests\Feature\Storefront;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Privacy Policy, Terms and data deletion pages (needed by Google sign-in and Meta). */
class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_pages_load_and_are_linked_everywhere_they_should_be(): void
    {
        $this->get(route('privacy'))->assertOk()->assertSee('Privacy Policy')->assertSee('not sell</strong> your personal information', false)->assertSee('Sign in with Google');
        $this->get(route('terms'))->assertOk()->assertSee('Terms of Service')->assertSee('Fit Guarantee');
        $this->get(route('data-deletion'))->assertOk();

        $this->get(route('products.index'))->assertOk()
            ->assertSee(route('privacy'), false)->assertSee(route('terms'), false)->assertSee(route('data-deletion'), false);
        $this->get(route('register'))->assertOk()->assertSee('By creating an account you agree');
    }
}
