<?php

declare(strict_types=1);

namespace WinCart\Mcp\Tools;

use WinCart\Admin\AdminMetricAggregator;

final class GetStoreMetricsTool implements ToolInterface
{
    public function __construct(private AdminMetricAggregator $aggregator)
    {
    }

    public function getName(): string
    {
        return 'get_store_metrics';
    }

    public function getDescription(): string
    {
        return 'Retrieve aggregated real-time store metrics including sales revenue, order counts, '
            . 'customer counts, and stock alerts.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => (object)[],
            'required' => [],
        ];
    }

    public function execute(array $arguments): ToolResult
    {
        $metrics = $this->aggregator->getMetrics();

        $data = [
            'total_orders' => $metrics['total_orders'],
            'total_revenue' => $metrics['total_revenue'],
            'total_sales' => $metrics['total_revenue'],
            'formatted_revenue' => $metrics['formatted_revenue'],
            'formatted_sales' => $metrics['formatted_revenue'],
            'pending_orders' => $metrics['pending_orders'],
            'completed_orders' => $metrics['completed_orders'],
            'low_stock_count' => $metrics['low_stock_products'],
            'low_stock_products' => $metrics['low_stock_products'],
            'out_of_stock_count' => $metrics['out_of_stock_products'],
            'out_of_stock_products' => $metrics['out_of_stock_products'],
            'total_products' => $metrics['total_products'],
            'customer_count' => $metrics['total_customers'],
            'total_customers' => $metrics['total_customers'],
        ];

        return ToolResult::json($data);
    }
}
