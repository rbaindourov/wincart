<?php

declare(strict_types=1);

namespace WinCart\Mcp\Prompts;

use PDO;
use WinCart\Admin\AdminMetricAggregator;

final class DailyStoreBriefingPrompt implements PromptInterface
{
    public function __construct(
        private PDO $pdo,
        private AdminMetricAggregator $aggregator
    ) {
    }

    public function getName(): string
    {
        return 'daily_store_briefing';
    }

    public function getDescription(): string
    {
        return 'Generates autonomous agent briefing prompt summarizing yesterday\'s sales and pending orders';
    }

    public function getArguments(): array
    {
        return [
            [
                'name' => 'date',
                'description' => 'Target date in YYYY-MM-DD format (defaults to current date)',
                'required' => false,
            ],
        ];
    }

    public function get(array $arguments): PromptResult
    {
        $date = (string)($arguments['date'] ?? date('Y-m-d'));
        $metrics = $this->aggregator->getMetrics();

        $text = <<<PROMPT
# Daily Store Briefing — {$date}

## Executive Summary
- Total Sales: {$metrics['formatted_revenue']}
- Total Orders: {$metrics['total_orders']}
- Pending Orders: {$metrics['pending_orders']}
- Completed Orders: {$metrics['completed_orders']}
- Low Stock Items: {$metrics['low_stock_products']}
- Out of Stock Items: {$metrics['out_of_stock_products']}

## Immediate Action Priorities
1. **Fulfill Pending Orders**:
   - Query pending orders using `list_orders(status="pending")`.
   - Inspect line items with `get_order(id=...)`.
   - Update order status with tracking numbers via `update_order_status(id=..., status="completed", note="...")`.

2. **Inventory Replenishment**:
   - Inspect low-stock inventory via resource `store://catalog/inventory-alerts`.
   - Restock critical items using `update_product(id=..., stock_quantity=...)`.
PROMPT;

        return new PromptResult(
            description: "Daily briefing for autonomous store manager agent",
            messages: [
                [
                    'role' => 'user',
                    'content' => [
                        'type' => 'text',
                        'text' => $text,
                    ],
                ],
            ]
        );
    }
}
