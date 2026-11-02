<?php

declare(strict_types=1);

namespace WinCart\Mcp\Resources;

use PDO;
use WinCart\Admin\AdminMetricAggregator;
use WinCart\Common\Money;

final class StoreMetricsResource implements ResourceInterface
{
    public function __construct(
        private PDO $pdo,
        private AdminMetricAggregator $aggregator
    ) {
    }

    public function getUri(): string
    {
        return 'store://metrics/daily';
    }

    public function getName(): string
    {
        return 'Daily Store Metrics';
    }

    public function getDescription(): string
    {
        return 'Daily store metrics including sales, orders, and top selling products';
    }

    public function getMimeType(): string
    {
        return 'application/json';
    }

    public function read(): ResourceContent
    {
        $metrics = $this->aggregator->getMetrics();

        $dailySales = (int)$this->pdo->query(
            "SELECT COALESCE(SUM(grand_total), 0) FROM orders "
            . "WHERE status IN ('processing', 'completed') AND DATE(created_at) = DATE('now')"
        )->fetchColumn();

        if ($dailySales === 0) {
            $dailySales = (int)$this->pdo->query(
                "SELECT COALESCE(SUM(grand_total), 0) FROM orders WHERE status IN ('processing', 'completed')"
            )->fetchColumn();
        }

        $topStmt = $this->pdo->query(
            "SELECT product_id, product_name, product_sku, SUM(quantity) as quantity_sold, "
            . "SUM(line_subtotal) as total_revenue "
            . "FROM order_items "
            . "GROUP BY product_id, product_name, product_sku "
            . "ORDER BY quantity_sold DESC "
            . "LIMIT 5"
        );
        $topProducts = $topStmt ? $topStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $payload = [
            'date' => date('Y-m-d'),
            'daily_sales_cents' => $dailySales,
            'daily_sales_formatted' => (new Money($dailySales, 'USD'))->format(),
            'total_orders' => $metrics['total_orders'],
            'pending_orders' => $metrics['pending_orders'],
            'completed_orders' => $metrics['completed_orders'],
            'top_products' => $topProducts,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return new ResourceContent($this->getUri(), $this->getMimeType(), (string)$json);
    }
}
