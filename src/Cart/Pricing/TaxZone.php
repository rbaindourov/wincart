<?php

declare(strict_types=1);

namespace WinCart\Cart\Pricing;

use JsonSerializable;

final class TaxZone implements JsonSerializable
{
    public function __construct(
        private ?int $id,
        private string $country,
        private string $state,
        private string $name,
        private float $rate,
        private int $flatShippingRate = 0,
        private ?int $freeShippingThreshold = null,
        private bool $isActive = true
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getRate(): float
    {
        return $this->rate;
    }

    public function getFlatShippingRate(): int
    {
        return $this->flatShippingRate;
    }

    public function getFreeShippingThreshold(): ?int
    {
        return $this->freeShippingThreshold;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'country' => $this->country,
            'state' => $this->state,
            'name' => $this->name,
            'rate' => $this->rate,
            'flat_shipping_rate' => $this->flatShippingRate,
            'free_shipping_threshold' => $this->freeShippingThreshold,
            'is_active' => $this->isActive,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
