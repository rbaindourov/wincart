<?php

declare(strict_types=1);

namespace WinCart\Common;

/**
 * Backed string enum representing product catalog types.
 */
enum ProductType: string
{
    case Simple = 'simple';
    case Variable = 'variable';
    case Downloadable = 'downloadable';

    /**
     * Human-readable product type label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Simple => 'Simple Product',
            self::Variable => 'Variable Product',
            self::Downloadable => 'Downloadable Product',
        };
    }

    public function isSimple(): bool
    {
        return $this === self::Simple;
    }

    public function isVariable(): bool
    {
        return $this === self::Variable;
    }

    public function isDownloadable(): bool
    {
        return $this === self::Downloadable;
    }

    /**
     * Whether products of this type require physical shipping rates and addresses.
     */
    public function requiresShipping(): bool
    {
        return $this !== self::Downloadable;
    }
}
