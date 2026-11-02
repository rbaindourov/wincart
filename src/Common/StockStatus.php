<?php

declare(strict_types=1);

namespace WinCart\Common;

/**
 * Backed string enum representing inventory availability status.
 */
enum StockStatus: string
{
    case InStock = 'instock';
    case OutOfStock = 'outofstock';
    case OnBackorder = 'onbackorder';

    /**
     * Human-readable inventory label.
     */
    public function label(): string
    {
        return match ($this) {
            self::InStock => 'In Stock',
            self::OutOfStock => 'Out of Stock',
            self::OnBackorder => 'On Backorder',
        };
    }

    /**
     * Whether items with this status can be added to the shopping cart.
     */
    public function isAvailable(): bool
    {
        return match ($this) {
            self::InStock, self::OnBackorder => true,
            self::OutOfStock => false,
        };
    }

    /**
     * Helper to compute stock status from physical count and store backorder policy.
     */
    public static function fromQuantity(int $quantity, bool $backordersAllowed = false): self
    {
        if ($quantity > 0) {
            return self::InStock;
        }

        return $backordersAllowed ? self::OnBackorder : self::OutOfStock;
    }
}
