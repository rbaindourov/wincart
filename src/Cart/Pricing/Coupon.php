<?php

declare(strict_types=1);

namespace WinCart\Cart\Pricing;

use DateTimeImmutable;
use JsonSerializable;
use WinCart\Common\Money;

final class Coupon implements JsonSerializable
{
    public function __construct(
        private ?int $id,
        private string $code,
        private string $discountType, // 'percentage' | 'fixed'
        private int $discountValue,    // basis points/percentage (e.g. 15 for 15%) or cents for fixed
        private int $minSubtotal = 0,
        private ?int $usageLimit = null,
        private int $usageCount = 0,
        private ?DateTimeImmutable $startsAt = null,
        private ?DateTimeImmutable $expiresAt = null,
        private bool $isActive = true
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getDiscountType(): string
    {
        return $this->discountType;
    }

    public function getDiscountValue(): int
    {
        return $this->discountValue;
    }

    public function getMinSubtotal(): int
    {
        return $this->minSubtotal;
    }

    public function getUsageLimit(): ?int
    {
        return $this->usageLimit;
    }

    public function getUsageCount(): int
    {
        return $this->usageCount;
    }

    public function getStartsAt(): ?DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }
        return new DateTimeImmutable() > $this->expiresAt;
    }

    public function isValid(?Money $subtotal = null): bool
    {
        if (!$this->isActive) {
            return false;
        }

        if ($this->isExpired()) {
            return false;
        }

        if ($this->startsAt !== null && new DateTimeImmutable() < $this->startsAt) {
            return false;
        }

        if ($this->usageLimit !== null && $this->usageCount >= $this->usageLimit) {
            return false;
        }

        if ($subtotal !== null && $subtotal->getAmount() < $this->minSubtotal) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'discount_type' => $this->discountType,
            'discount_value' => $this->discountValue,
            'min_subtotal' => $this->minSubtotal,
            'usage_limit' => $this->usageLimit,
            'usage_count' => $this->usageCount,
            'is_active' => $this->isActive,
            'is_expired' => $this->isExpired(),
            'starts_at' => $this->startsAt?->format(DateTimeImmutable::ATOM),
            'expires_at' => $this->expiresAt?->format(DateTimeImmutable::ATOM),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
