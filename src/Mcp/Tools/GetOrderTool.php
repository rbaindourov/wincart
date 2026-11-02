<?php

declare(strict_types=1);

namespace WinCart\Mcp\Tools;

use WinCart\Checkout\OrderRepositoryInterface;

final class GetOrderTool implements ToolInterface
{
    public function __construct(private OrderRepositoryInterface $orderRepo)
    {
    }

    public function getName(): string
    {
        return 'get_order';
    }

    public function getDescription(): string
    {
        return 'Retrieve full order details including line items, customer address, payment status, '
            . 'and totals by ID or order number.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'integer',
                    'description' => 'Order ID (integer)',
                ],
                'order_id' => [
                    'type' => 'integer',
                    'description' => 'Alternative alias for order ID',
                ],
                'order_number' => [
                    'type' => 'string',
                    'description' => 'Order number string (e.g. "WC-2001")',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments): ToolResult
    {
        $id = $arguments['id'] ?? $arguments['order_id'] ?? null;
        $order = null;

        if ($id !== null && (int)$id > 0) {
            $order = $this->orderRepo->findById((int)$id);
        } elseif (isset($arguments['order_number']) && trim((string)$arguments['order_number']) !== '') {
            $order = $this->orderRepo->findByOrderNumber(trim((string)$arguments['order_number']));
        } else {
            return ToolResult::text("Error: Must provide either 'id', 'order_id', or 'order_number'", isError: true);
        }

        if ($order === null) {
            return ToolResult::text("Error: Order not found", isError: true);
        }

        return ToolResult::json($order->toArray());
    }
}
