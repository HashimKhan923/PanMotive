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
}
