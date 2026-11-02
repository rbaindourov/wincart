<?php

declare(strict_types=1);

namespace WinCart\Cart\Pricing;

use PDO;
use WinCart\Common\Money;

final class TaxEngine
{
    /** @var array<string, float> Default statutory tax rates */
    private const DEFAULT_RATES = [
        'WA' => 0.1025,
        'CA' => 0.0850,
        'NY' => 0.08875,
        'IL' => 0.0825,
        'OR' => 0.0000,
    ];

    public function __construct(private ?PDO $pdo = null)
    {
    }

    /**
     * Calculate destination-based tax on discounted subtotal.
     */
    public function calculateTax(Money $taxableSubtotal, string $state, string $country = 'US'): TaxResult
    {
        $normalizedState = strtoupper(trim($state));
        $normalizedCountry = strtoupper(trim($country));
        $rate = $this->lookupRate($normalizedState, $normalizedCountry);

        $taxCents = (int)round($taxableSubtotal->getAmount() * $rate, 0, PHP_ROUND_HALF_UP);
        $taxAmount = new Money($taxCents, $taxableSubtotal->getCurrency());

        return new TaxResult(
            taxAmount: $taxAmount,
            taxRate: $rate,
            taxZone: $normalizedState,
            state: $normalizedState,
            country: $normalizedCountry
        );
    }

    private function lookupRate(string $state, string $country): float
    {
        if ($this->pdo !== null) {
            try {
                $stmt = $this->pdo->prepare(
                    'SELECT rate FROM tax_zones
                     WHERE UPPER(state) = :state AND UPPER(country) = :country AND is_active = 1'
                );
                $stmt->execute(['state' => $state, 'country' => $country]);
                $val = $stmt->fetchColumn();
                if ($val !== false && is_numeric($val)) {
                    return (float)$val;
                }
            } catch (\Throwable) {
                // Fallback to defaults on DB error
            }
        }

        return self::DEFAULT_RATES[$state] ?? 0.0;
    }
}
