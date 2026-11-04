<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TwoFactorSetupTest extends TestCase
{
    use RefreshDatabase;

    /** Local mode skips this gate, so a missing QR library only ever crashed on the live site. */
    public function test_setup_page_draws_a_qr_code(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('two-factor.setup'))
            ->assertOk()
            ->assertSee('<svg', false);
    }

    /** Servers with Imagick get a PNG data address, which must be an image and not printed as text. */
    public function test_setup_page_shows_a_png_qr_code_as_an_image(): void
    {
        $this->actingAs(User::factory()->create());

        $html = view('auth.two-factor.setup', [
            'secret' => 'ABC123',
            'qrUrl' => 'otpauth://totp/test',
            'qrImage' => 'data:image/png;base64,iVBORw0KGgo=',
            'errors' => new \Illuminate\Support\ViewErrorBag,
        ])->render();

        $this->assertStringContainsString('<img src="data:image/png;base64,iVBORw0KGgo="', $html);
        $this->assertStringNotContainsString('>data:image/png', $html);
    }
}
