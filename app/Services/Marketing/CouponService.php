<?php

namespace App\Services\Marketing;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\User;

class CouponService
{
    /**
     * @return array{valid: bool, message?: string, coupon?: Coupon, discount?: float}
     */
    public function validate(string $code, ?User $user, float $subtotal): array
    {
        $coupon = Coupon::where('code', strtoupper($code))->where('is_active', true)->first();

        if (! $coupon) {
            return ['valid' => false, 'message' => 'Invalid coupon code.'];
        }

        if ($coupon->starts_at && now()->lt($coupon->starts_at)) {
            return ['valid' => false, 'message' => 'This coupon isn\'t active yet.'];
        }

        if ($coupon->expires_at && now()->gt($coupon->expires_at)) {
            return ['valid' => false, 'message' => 'This coupon has expired.'];
        }

        if ($coupon->usage_limit && $coupon->times_used >= $coupon->usage_limit) {
            return ['valid' => false, 'message' => 'This coupon has reached its usage limit.'];
        }

        if ($coupon->min_order_total && $subtotal < (float) $coupon->min_order_total) {
            return ['valid' => false, 'message' => "Minimum order of \${$coupon->min_order_total} required."];
        }

        if ($user && $coupon->usage_limit_per_user) {
            $userUsage = $coupon->usages()->where('user_id', $user->id)->count();
            if ($userUsage >= $coupon->usage_limit_per_user) {
                return ['valid' => false, 'message' => 'You\'ve already used this coupon.'];
            }
        }

        return ['valid' => true, 'coupon' => $coupon, 'discount' => $this->calculateDiscount($coupon, $subtotal)];
    }

    public function calculateDiscount(Coupon $coupon, float $subtotal): float
    {
        $discount = $coupon->type === 'percentage'
            ? $subtotal * ((float) $coupon->value / 100)
            : (float) $coupon->value;

        if ($coupon->max_discount_amount) {
            $discount = min($discount, (float) $coupon->max_discount_amount);
        }

        return round(min($discount, $subtotal), 2);
    }

    public function redeem(Coupon $coupon, User $user, Order $order, float $discountAmount): void
    {
        $coupon->increment('times_used');
        $coupon->usages()->create([
            'user_id' => $user->id,
            'order_id' => $order->id,
            'discount_amount' => $discountAmount,
        ]);
    }
}
