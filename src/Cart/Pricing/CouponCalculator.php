<?php

declare(strict_types=1);

namespace WinCart\Cart\Pricing;

use WinCart\Common\Money;

final class CouponCalculator
{
    /**
     * Calculate discount amount in Money units based on coupon rules.
     */
    public static function calculateDiscount(Coupon $coupon, Money $subtotal): Money
    {
        if (!$coupon->isValid($subtotal)) {
            return Money::zero($subtotal->getCurrency());
        }

        $type = strtolower($coupon->getDiscountType());
        $subtotalAmount = $subtotal->getAmount();

        if ($type === 'percentage') {
            $percent = $coupon->getDiscountValue();
            $discountCents = (int)round($subtotalAmount * ($percent / 100.0), 0, PHP_ROUND_HALF_UP);
            $finalCents = min($discountCents, $subtotalAmount);
            return new Money($finalCents, $subtotal->getCurrency());
        }

        if ($type === 'fixed') {
            $discountCents = $coupon->getDiscountValue();
            $finalCents = min($discountCents, $subtotalAmount);
            return new Money($finalCents, $subtotal->getCurrency());
        }

        return Money::zero($subtotal->getCurrency());
    }
}
