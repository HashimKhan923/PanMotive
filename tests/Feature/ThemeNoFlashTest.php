<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeNoFlashTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_server_sends_dark_pages_already_dark(): void
    {
        foreach (['/', '/login'] as $path) {
            $this->withUnencryptedCookie('pm-theme', 'dark')->get($path)
                ->assertOk()
                ->assertSee('class="js dark"', false);
        }
    }

    public function test_light_and_new_visitors_are_not_sent_dark(): void
    {
        $this->withUnencryptedCookie('pm-theme', 'light')->get('/')->assertDontSee('class="js dark"', false);

        $this->flushHeaders();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->get('/')->assertDontSee('class="js dark"', false);
    }

    public function test_an_unexpected_cookie_value_changes_nothing(): void
    {
        $this->withUnencryptedCookie('pm-theme', '"><script>alert(1)</script>')->get('/')
            ->assertOk()
            ->assertDontSee('alert(1)', false)
            ->assertDontSee('class="js dark"', false);
    }

    public function test_the_theme_script_opts_out_of_rocket_loader(): void
    {
        $this->get('/')
            ->assertSee('<script data-cfasync="false"', false)
            ->assertSee("matchMedia('(prefers-color-scheme: dark)')", false);
    }
}
