<?php

declare(strict_types=1);

namespace WinCart\Catalog\Models;

use JsonSerializable;
use WinCart\Common\Money;
use WinCart\Common\StockStatus;

final class Variation implements JsonSerializable
{
    /**
     * @param array<string, string> $attributeMap e.g. ['size' => 'Large', 'color' => 'Black']
     */
    public function __construct(
        private ?int $id,
        private int $productId,
        private string $sku,
        private Money $price,
        private ?Money $salePrice = null,
        private int $stockQuantity = 0,
        private StockStatus $stockStatus = StockStatus::InStock,
        private bool $manageStock = true,
        private array $attributeMap = [],
        private ?string $imageUrl = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProductId(): int
    {
        return $this->productId;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getPrice(): Money
    {
        return $this->price;
    }

    public function getSalePrice(): ?Money
    {
        return $this->salePrice;
    }

    public function getStockQuantity(): int
    {
        return $this->stockQuantity;
    }

    public function getStockStatus(): StockStatus
    {
        return $this->stockStatus;
    }

    public function isManageStock(): bool
    {
        return $this->manageStock;
    }

    /**
     * @return array<string, string>
     */
    public function getAttributeMap(): array
    {
        return $this->attributeMap;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function getEffectivePrice(): Money
    {
        if ($this->salePrice !== null && $this->salePrice->lessThan($this->price)) {
            return $this->salePrice;
        }
        return $this->price;
    }

    public function isAvailable(int $quantity = 1): bool
    {
        if (!$this->manageStock) {
            return $this->stockStatus->isAvailable();
        }
        return $this->stockQuantity >= $quantity && $this->stockStatus === StockStatus::InStock;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->productId,
            'sku' => $this->sku,
            'price' => $this->price->getAmount(),
            'sale_price' => $this->salePrice?->getAmount(),
            'effective_price' => $this->getEffectivePrice()->getAmount(),
            'formatted_price' => $this->getEffectivePrice()->format(),
            'stock_quantity' => $this->stockQuantity,
            'stock_status' => match ($this->stockStatus) {
                StockStatus::InStock => 'in_stock',
                StockStatus::OutOfStock => 'out_of_stock',
                StockStatus::OnBackorder => 'on_backorder',
            },
            'manage_stock' => $this->manageStock,
            'attributes' => $this->attributeMap,
            'image_url' => $this->imageUrl,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
