<?php

declare(strict_types=1);

namespace WinCart\Common;

/**
 * Backed string enum representing customer order lifecycle states.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    /**
     * Human-readable display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending Payment',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Refunded => 'Refunded',
        };
    }

    /**
     * Whether this status represents a terminal state (cannot transition further).
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Cancelled, self::Refunded => true,
            default => false,
        };
    }

    /**
     * Finite State Machine: validate legal transitions between order states.
     *
     * Rules:
     * - Same state is always a no-op (true).
     * - Pending -> Processing, Cancelled.
     * - Processing -> Completed, Cancelled, Refunded.
     * - Completed -> Refunded.
     * - Cancelled -> none (terminal).
     * - Refunded -> none (terminal).
     */
    public function canTransitionTo(OrderStatus $newStatus): bool
    {
        if ($this === $newStatus) {
            return true;
        }

        return match ($this) {
            self::Pending => in_array($newStatus, [self::Processing, self::Cancelled], true),
            self::Processing => in_array($newStatus, [self::Completed, self::Cancelled, self::Refunded], true),
            self::Completed => in_array($newStatus, [self::Refunded], true),
            self::Cancelled, self::Refunded => false,
        };
    }

    /**
     * CSS badge class for storefront and admin dashboard templates.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending => 'badge-warning',
            self::Processing => 'badge-info',
            self::Completed => 'badge-success',
            self::Cancelled => 'badge-danger',
            self::Refunded => 'badge-secondary',
        };
    }
}
