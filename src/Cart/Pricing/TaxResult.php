<?php

declare(strict_types=1);

namespace WinCart\Cart\Pricing;

use JsonSerializable;
use WinCart\Common\Money;

final class TaxResult implements JsonSerializable
{
    public function __construct(
        private Money $taxAmount,
        private float $taxRate,
        private string $taxZone,
        private string $state,
        private string $country = 'US'
    ) {
    }

    public function getTaxAmount(): Money
    {
        return $this->taxAmount;
    }

    public function getTaxRate(): float
    {
        return $this->taxRate;
    }

    public function getTaxZone(): string
    {
        return $this->taxZone;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tax_amount' => $this->taxAmount->getAmount(),
            'tax_rate' => $this->taxRate,
            'tax_zone' => $this->taxZone,
            'state' => $this->state,
            'country' => $this->country,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
