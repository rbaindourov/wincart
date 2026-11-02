<?php

declare(strict_types=1);

namespace WinCart\Cart\Pricing;

use PDO;
use WinCart\Cart\Models\Cart;
use WinCart\Common\Money;

final class ShippingCalculator
{
    private const DEFAULT_FLAT_RATE = 750; // $7.50 in cents
    private const DEFAULT_FREE_SHIPPING_THRESHOLD = 15000; // $150.00 in cents

    public function __construct(private ?PDO $pdo = null)
    {
    }

    /**
     * Calculate shipping rate based on cart subtotal, destination, and method.
     */
    public function calculateShipping(
        Cart $cart,
        Money $discountedSubtotal,
        string $method = 'standard_ground',
        ?string $state = null
    ): ShippingResult {
        // If cart does not require shipping, return 0
        if (!$cart->requiresShipping()) {
            return new ShippingResult(
                shippingAmount: Money::zero($discountedSubtotal->getCurrency()),
                shippingMethod: 'digital_delivery',
                isFree: true
            );
        }

        $freeThreshold = $this->getFreeShippingThreshold($state);
        if ($freeThreshold !== null && $discountedSubtotal->getAmount() >= $freeThreshold) {
            return new ShippingResult(
                shippingAmount: Money::zero($discountedSubtotal->getCurrency()),
                shippingMethod: $method,
                isFree: true
            );
        }

        $flatRate = $this->getFlatRate($state);
        return new ShippingResult(
            shippingAmount: new Money($flatRate, $discountedSubtotal->getCurrency()),
            shippingMethod: $method,
            isFree: false
        );
    }

    private function getFlatRate(?string $state): int
    {
        if ($this->pdo !== null) {
            try {
                if ($state !== null && $state !== '') {
                    $zoneStmt = $this->pdo->prepare(
                        'SELECT flat_shipping_rate FROM tax_zones
                         WHERE UPPER(state) = :state AND is_active = 1'
                    );
                    $zoneStmt->execute(['state' => strtoupper(trim($state))]);
                    $rate = $zoneStmt->fetchColumn();
                    if ($rate !== false && is_numeric($rate) && (int)$rate > 0) {
                        return (int)$rate;
                    }
                }

                $genStmt = $this->pdo->query(
                    'SELECT flat_shipping_rate FROM tax_zones WHERE is_active = 1 LIMIT 1'
                );
                $genRate = $genStmt ? $genStmt->fetchColumn() : false;
                if ($genRate !== false && is_numeric($genRate) && (int)$genRate > 0) {
                    return (int)$genRate;
                }
            } catch (\Throwable) {
                // Fallback to default
            }
        }

        return self::DEFAULT_FLAT_RATE;
    }

    private function getFreeShippingThreshold(?string $state): ?int
    {
        if ($this->pdo !== null) {
            try {
                $optStmt = $this->pdo->query("SELECT value FROM options WHERE key = 'free_shipping_threshold'");
                $val = $optStmt ? $optStmt->fetchColumn() : false;
                if ($val !== false && is_numeric($val)) {
                    return (int)$val;
                }

                if ($state !== null) {
                    $zoneStmt = $this->pdo->prepare(
                        'SELECT free_shipping_threshold FROM tax_zones WHERE UPPER(state) = :state AND is_active = 1'
                    );
                    $zoneStmt->execute(['state' => strtoupper(trim($state))]);
                    $thresh = $zoneStmt->fetchColumn();
                    if ($thresh !== false && is_numeric($thresh)) {
                        return (int)$thresh;
                    }
                }
            } catch (\Throwable) {
                // Fallback
            }
        }

        return self::DEFAULT_FREE_SHIPPING_THRESHOLD;
    }
}
