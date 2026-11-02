<?php

declare(strict_types=1);

namespace WinCart\Cart\Models;

use JsonSerializable;
use WinCart\Common\Money;

final class CartTotals implements JsonSerializable
{
    public function __construct(
        private Money $subtotal,
        private Money $discountAmount,
        private Money $discountedSubtotal,
        private Money $taxAmount,
        private Money $shippingAmount,
        private Money $grandTotal,
        private ?string $couponCode = null,
        private ?string $taxZone = null
    ) {
    }

    public function getSubtotal(): Money
    {
        return $this->subtotal;
    }

    public function getDiscountAmount(): Money
    {
        return $this->discountAmount;
    }

    public function getDiscount(): Money
    {
        return $this->discountAmount;
    }

    public function getDiscountedSubtotal(): Money
    {
        return $this->discountedSubtotal;
    }

    public function getTaxAmount(): Money
    {
        return $this->taxAmount;
    }

    public function getTax(): Money
    {
        return $this->taxAmount;
    }

    public function getShippingAmount(): Money
    {
        return $this->shippingAmount;
    }

    public function getShipping(): Money
    {
        return $this->shippingAmount;
    }

    public function getGrandTotal(): Money
    {
        return $this->grandTotal;
    }

    public function getCouponCode(): ?string
    {
        return $this->couponCode;
    }

    public function getTaxZone(): ?string
    {
        return $this->taxZone;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'subtotal' => $this->subtotal->getAmount(),
            'discount_amount' => $this->discountAmount->getAmount(),
            'discounted_subtotal' => $this->discountedSubtotal->getAmount(),
            'tax_amount' => $this->taxAmount->getAmount(),
            'shipping_amount' => $this->shippingAmount->getAmount(),
            'grand_total' => $this->grandTotal->getAmount(),
            'formatted_total' => $this->grandTotal->format(),
            'coupon_code' => $this->couponCode,
            'tax_zone' => $this->taxZone,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
