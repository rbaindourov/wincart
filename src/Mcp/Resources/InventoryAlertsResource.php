<?php

declare(strict_types=1);

namespace WinCart\Mcp\Resources;

use DateTimeImmutable;
use PDO;

final class InventoryAlertsResource implements ResourceInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function getUri(): string
    {
        return 'store://catalog/inventory-alerts';
    }

    public function getName(): string
    {
        return 'Inventory Alerts';
    }

    public function getDescription(): string
    {
        return 'Inventory alerts for low-stock (stock <= 3) and out-of-stock products';
    }

    public function getMimeType(): string
    {
        return 'application/json';
    }

    public function read(): ResourceContent
    {
        $stmt = $this->pdo->query(
            "SELECT id, name, sku, price, stock_quantity, stock_status "
            . "FROM products "
            . "WHERE manage_stock = 1 "
            . "AND (stock_quantity <= 3 OR stock_status = 'out_of_stock' OR stock_status = 'outofstock') "
            . "ORDER BY stock_quantity ASC "
            . "LIMIT 50"
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $alerts = array_map(function (array $r) {
            $stock = (int)$r['stock_quantity'];
            return [
                'id' => (int)$r['id'],
                'name' => (string)$r['name'],
                'sku' => (string)$r['sku'],
                'price_cents' => (int)$r['price'],
                'stock_quantity' => $stock,
                'stock_status' => (string)$r['stock_status'],
                'alert_type' => $stock <= 0 ? 'out_of_stock' : 'low_stock',
            ];
        }, $rows);

        $payload = [
            'generated_at' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
            'alert_count' => count($alerts),
            'low_stock_threshold' => 3,
            'alerts' => $alerts,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return new ResourceContent($this->getUri(), $this->getMimeType(), (string)$json);
    }
}
