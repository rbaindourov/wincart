<?php

declare(strict_types=1);

namespace WinCart\Checkout;

use DateTimeImmutable;
use JsonSerializable;
use WinCart\Common\Money;

final class OrderItem implements JsonSerializable
{
    public function __construct(
        private ?int $id,
        private int $orderId,
        private ?int $productId,
        private string $productName,
        private string $productSku,
        private Money $unitPrice,
        private int $quantity,
        private Money $lineSubtotal,
        private ?int $variationId = null,
        private ?string $attributesJson = null,
        private ?DateTimeImmutable $createdAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrderId(): int
    {
        return $this->orderId;
    }

    public function getProductId(): ?int
    {
        return $this->productId;
    }

    public function getVariationId(): ?int
    {
        return $this->variationId;
    }

    public function getProductName(): string
    {
        return $this->productName;
    }

    public function getProductSku(): string
    {
        return $this->productSku;
    }

    public function getAttributesJson(): ?string
    {
        return $this->attributesJson;
    }

    public function getUnitPrice(): Money
    {
        return $this->unitPrice;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getLineSubtotal(): Money
    {
        return $this->lineSubtotal;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->orderId,
            'product_id' => $this->productId,
            'variation_id' => $this->variationId,
            'product_name' => $this->productName,
            'name' => $this->productName,
            'sku' => $this->productSku,
            'unit_price' => $this->unitPrice->getAmount(),
            'quantity' => $this->quantity,
            'line_subtotal' => $this->lineSubtotal->getAmount(),
            'subtotal' => $this->lineSubtotal->getAmount(),
            'formatted_subtotal' => $this->lineSubtotal->format(),
            'attributes' => $this->attributesJson ? json_decode($this->attributesJson, true) : [],
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
