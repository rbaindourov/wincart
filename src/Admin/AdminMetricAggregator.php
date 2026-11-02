<?php

declare(strict_types=1);

namespace WinCart\Admin;

use PDO;
use WinCart\Common\Money;

final class AdminMetricAggregator
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{
     *     total_orders: int,
     *     total_revenue: int,
     *     formatted_revenue: string,
     *     pending_orders: int,
     *     completed_orders: int,
     *     low_stock_products: int,
     *     out_of_stock_products: int,
     *     total_products: int,
     *     total_customers: int
     * }
     */
    public function getMetrics(): array
    {
        $totalOrders = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM orders WHERE status != 'cancelled'"
        )->fetchColumn();

        $totalRevenueCents = (int)$this->pdo->query(
            "SELECT COALESCE(SUM(grand_total), 0) FROM orders WHERE status IN ('processing', 'completed')"
        )->fetchColumn();

        $pendingOrders = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM orders WHERE status = 'pending'"
        )->fetchColumn();

        $completedOrders = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM orders WHERE status = 'completed'"
        )->fetchColumn();

        $lowStock = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM products WHERE stock_quantity <= 5 AND stock_quantity > 0 AND manage_stock = 1"
        )->fetchColumn();

        $outOfStock = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM products WHERE stock_quantity <= 0 AND manage_stock = 1"
        )->fetchColumn();

        $totalProducts = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM products"
        )->fetchColumn();

        $totalCustomers = (int)$this->pdo->query(
            "SELECT COUNT(*) FROM customers"
        )->fetchColumn();

        $revenue = new Money($totalRevenueCents, 'USD');

        return [
            'total_orders' => $totalOrders,
            'total_revenue' => $totalRevenueCents,
            'formatted_revenue' => $revenue->format(),
            'pending_orders' => $pendingOrders,
            'completed_orders' => $completedOrders,
            'low_stock_products' => $lowStock,
            'out_of_stock_products' => $outOfStock,
            'total_products' => $totalProducts,
            'total_customers' => $totalCustomers,
        ];
    }
}
