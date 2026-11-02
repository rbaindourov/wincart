<?php

declare(strict_types=1);

namespace WinCart\Cart\Pricing;

use JsonSerializable;
use WinCart\Common\Money;

final class ShippingResult implements JsonSerializable
{
    public function __construct(
        private Money $shippingAmount,
        private string $shippingMethod,
        private bool $isFree
    ) {
    }

    public function getShippingAmount(): Money
    {
        return $this->shippingAmount;
    }

    public function getShippingMethod(): string
    {
        return $this->shippingMethod;
    }

    public function isFree(): bool
    {
        return $this->isFree;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'shipping_amount' => $this->shippingAmount->getAmount(),
            'shipping_method' => $this->shippingMethod,
            'is_free' => $this->isFree,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
