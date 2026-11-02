<?php

declare(strict_types=1);

namespace WinCart\Catalog\Models;

use DateTimeImmutable;
use JsonSerializable;
use WinCart\Common\Money;
use WinCart\Common\ProductType;
use WinCart\Common\StockStatus;

final class Product implements JsonSerializable
{
    /**
     * @param array<int> $categoryIds
     * @param array<Variation> $variations
     * @param array<string> $images
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        private ?int $id,
        private string $name,
        private string $slug,
        private string $sku,
        private ProductType $type,
        private Money $price,
        private ?Money $salePrice = null,
        private ?Money $compareAtPrice = null,
        private ?Money $costPrice = null,
        private ?string $description = null,
        private ?string $shortDescription = null,
        private int $stockQuantity = 0,
        private StockStatus $stockStatus = StockStatus::InStock,
        private bool $manageStock = true,
        private bool $backordersAllowed = false,
        private bool $requiresShipping = true,
        private ?float $weight = null,
        private bool $isActive = true,
        private array $categoryIds = [],
        private array $variations = [],
        private array $images = [],
        private array $attributes = [],
        private ?DateTimeImmutable $createdAt = null,
        private ?DateTimeImmutable $updatedAt = null
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getType(): ProductType
    {
        return $this->type;
    }

    public function getPrice(): Money
    {
        return $this->price;
    }

    public function getSalePrice(): ?Money
    {
        return $this->salePrice;
    }

    public function getCompareAtPrice(): ?Money
    {
        return $this->compareAtPrice;
    }

    public function getCostPrice(): ?Money
    {
        return $this->costPrice;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getShortDescription(): ?string
    {
        return $this->shortDescription;
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

    public function isBackordersAllowed(): bool
    {
        return $this->backordersAllowed;
    }

    public function requiresShipping(): bool
    {
        return $this->requiresShipping;
    }

    public function getWeight(): ?float
    {
        return $this->weight;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * @return array<int>
     */
    public function getCategoryIds(): array
    {
        return $this->categoryIds;
    }

    /**
     * @return array<Variation>
     */
    public function getVariations(): array
    {
        return $this->variations;
    }

    /**
     * @return array<string>
     */
    public function getImages(): array
    {
        return $this->images;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isOnSale(): bool
    {
        return $this->salePrice !== null && $this->salePrice->lessThan($this->price);
    }

    public function getEffectivePrice(): Money
    {
        return $this->isOnSale() && $this->salePrice !== null ? $this->salePrice : $this->price;
    }

    public function isAvailable(int $quantity = 1): bool
    {
        if (!$this->isActive) {
            return false;
        }

        if (!$this->manageStock) {
            return $this->stockStatus->isAvailable();
        }

        if ($this->backordersAllowed) {
            return true;
        }

        return $this->stockQuantity >= $quantity && $this->stockStatus === StockStatus::InStock;
    }

    public function isVariable(): bool
    {
        return $this->type === ProductType::Variable;
    }

    public function getVariation(int $variationId): ?Variation
    {
        foreach ($this->variations as $variation) {
            if ($variation->getId() === $variationId) {
                return $variation;
            }
        }
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'sku' => $this->sku,
            'type' => $this->type->value,
            'price' => $this->price->getAmount(),
            'sale_price' => $this->salePrice?->getAmount(),
            'compare_at_price' => $this->compareAtPrice?->getAmount(),
            'cost_price' => $this->costPrice?->getAmount(),
            'effective_price' => $this->getEffectivePrice()->getAmount(),
            'formatted_price' => $this->getEffectivePrice()->format(),
            'description' => $this->description,
            'short_description' => $this->shortDescription,
            'stock_quantity' => $this->stockQuantity,
            'stock_status' => match ($this->stockStatus) {
                StockStatus::InStock => 'in_stock',
                StockStatus::OutOfStock => 'out_of_stock',
                StockStatus::OnBackorder => 'on_backorder',
            },
            'manage_stock' => $this->manageStock,
            'backorders_allowed' => $this->backordersAllowed,
            'requires_shipping' => $this->requiresShipping,
            'weight' => $this->weight,
            'is_active' => $this->isActive,
            'category_ids' => $this->categoryIds,
            'images' => $this->images,
            'variations' => array_map(fn(Variation $v) => $v->toArray(), $this->variations),
            'attributes' => $this->attributes,
            'created_at' => $this->createdAt?->format(DateTimeImmutable::ATOM),
            'updated_at' => $this->updatedAt?->format(DateTimeImmutable::ATOM),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
