<?php

namespace Tests;

use App\Models\User;
use App\Support\SellerContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // SellerContext holds request-scoped statics; clear leftovers between tests.
        SellerContext::clear();
    }

    /**
     * Give a user a confirmed 2FA secret so the RequireTwoFactor enrolment gate
     * lets them into the seller/admin portals. (The login OTP challenge is a
     * separate flow, tested on its own.)
     */
    protected function withTwoFactor(User $user): User
    {
        $user->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => ['aaaa-bbbb', 'cccc-dddd'],
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user;
    }
}
