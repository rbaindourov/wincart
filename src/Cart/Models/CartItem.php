<?php

declare(strict_types=1);

namespace WinCart\Cart\Models;

use InvalidArgumentException;
use JsonSerializable;
use WinCart\Common\Money;
use WinCart\Common\ProductType;

final class CartItem implements JsonSerializable
{
    /**
     * @param array<string, string> $variationAttributes
     */
    public function __construct(
        private ?int $id,
        private int $cartId,
        private int $productId,
        private string $productName,
        private string $sku,
        private Money $unitPrice,
        private int $quantity,
        private ?int $variationId = null,
        private array $variationAttributes = [],
        private ProductType $productType = ProductType::Simple,
        private ?string $imageUrl = null
    ) {
        if ($this->quantity <= 0) {
            throw new InvalidArgumentException("Cart item quantity must be greater than zero: {$quantity}");
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCartId(): int
    {
        return $this->cartId;
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function getProductName(): string
    {
        return $this->productName;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getUnitPrice(): Money
    {
        return $this->unitPrice;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getVariationId(): ?int
    {
        return $this->variationId;
    }

    /**
     * @return array<string, string>
     */
    public function getVariationAttributes(): array
    {
        return $this->variationAttributes;
    }

    public function getProductType(): ProductType
    {
        return $this->productType;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function getLineTotal(): Money
    {
        return $this->unitPrice->multiply($this->quantity);
    }

    public function requiresShipping(): bool
    {
        return $this->productType->requiresShipping();
    }

    public function withQuantity(int $newQuantity): self
    {
        return new self(
            $this->id,
            $this->cartId,
            $this->productId,
            $this->productName,
            $this->sku,
            $this->unitPrice,
            $newQuantity,
            $this->variationId,
            $this->variationAttributes,
            $this->productType,
            $this->imageUrl
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'cart_id' => $this->cartId,
            'product_id' => $this->productId,
            'variation_id' => $this->variationId,
            'name' => $this->productName,
            'sku' => $this->sku,
            'unit_price' => $this->unitPrice->getAmount(),
            'quantity' => $this->quantity,
            'line_total' => $this->getLineTotal()->getAmount(),
            'formatted_line_total' => $this->getLineTotal()->format(),
            'attributes' => $this->variationAttributes,
            'image_url' => $this->imageUrl,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
