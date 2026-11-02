<?php

declare(strict_types=1);

namespace WinCart\Cart\Models;

use DateTimeImmutable;
use JsonSerializable;
use WinCart\Common\Address;
use WinCart\Common\Money;

final class Cart implements JsonSerializable
{
    /**
     * @param array<CartItem> $items
     */
    public function __construct(
        private ?int $id,
        private string $token,
        private ?int $customerId = null,
        private array $items = [],
        private ?string $couponCode = null,
        private ?Address $shippingAddress = null,
        private ?Address $billingAddress = null,
        private ?string $shippingMethod = null,
        private string $currency = 'USD',
        private ?DateTimeImmutable $createdAt = null,
        private ?DateTimeImmutable $updatedAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getCustomerId(): ?int
    {
        return $this->customerId;
    }

    /**
     * @return array<CartItem>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function getCouponCode(): ?string
    {
        return $this->couponCode;
    }

    public function getShippingAddress(): ?Address
    {
        return $this->shippingAddress;
    }

    public function getBillingAddress(): ?Address
    {
        return $this->billingAddress;
    }

    public function getShippingMethod(): ?string
    {
        return $this->shippingMethod;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    public function getItemCount(): int
    {
        $count = 0;
        foreach ($this->items as $item) {
            $count += $item->getQuantity();
        }
        return $count;
    }

    public function findItem(int $itemId): ?CartItem
    {
        foreach ($this->items as $item) {
            if ($item->getId() === $itemId) {
                return $item;
            }
        }
        return null;
    }

    public function findItemByProduct(int $productId, ?int $variationId = null): ?CartItem
    {
        foreach ($this->items as $item) {
            if ($item->getProductId() === $productId && $item->getVariationId() === $variationId) {
                return $item;
            }
        }
        return null;
    }

    public function getSubtotal(): Money
    {
        $total = Money::zero($this->currency);
        foreach ($this->items as $item) {
            $total = $total->add($item->getLineTotal());
        }
        return $total;
    }

    public function requiresShipping(): bool
    {
        foreach ($this->items as $item) {
            if ($item->requiresShipping()) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'token' => $this->token,
            'cart_token' => $this->token,
            'customer_id' => $this->customerId,
            'item_count' => $this->getItemCount(),
            'subtotal' => $this->getSubtotal()->getAmount(),
            'formatted_subtotal' => $this->getSubtotal()->format(),
            'currency' => $this->currency,
            'coupon_code' => $this->couponCode,
            'shipping_method' => $this->shippingMethod,
            'items' => array_map(fn(CartItem $i) => $i->toArray(), $this->items),
            'shipping_address' => $this->shippingAddress?->toArray(),
            'billing_address' => $this->billingAddress?->toArray(),
            'created_at' => $this->createdAt?->format(DateTimeImmutable::ATOM),
            'updated_at' => $this->updatedAt?->format(DateTimeImmutable::ATOM),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
