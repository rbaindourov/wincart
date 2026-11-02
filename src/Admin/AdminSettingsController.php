<?php

declare(strict_types=1);

namespace WinCart\Admin;

use InvalidArgumentException;
use PDO;

final class AdminSettingsController
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function apiUpdateShipping(array $data): array
    {
        if (!isset($data['flat_rate_amount']) || !is_numeric($data['flat_rate_amount'])) {
            throw new InvalidArgumentException('Validation failed: flat_rate_amount must be numeric');
        }

        $flatRateAmount = (int)$data['flat_rate_amount'];

        // Update all active tax zones flat shipping rate
        $stmt = $this->pdo->prepare('UPDATE tax_zones SET flat_shipping_rate = :flat');
        $stmt->execute([':flat' => $flatRateAmount]);

        return [
            'success' => true,
            'flat_rate_amount' => $flatRateAmount,
        ];
    }
}
