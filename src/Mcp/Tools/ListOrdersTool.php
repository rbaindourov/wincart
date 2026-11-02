<?php

declare(strict_types=1);

namespace WinCart\Mcp\Tools;

use DateTimeImmutable;
use WinCart\Checkout\Order;
use WinCart\Checkout\OrderRepositoryInterface;

final class ListOrdersTool implements ToolInterface
{
    public function __construct(private OrderRepositoryInterface $orderRepo)
    {
    }

    public function getName(): string
    {
        return 'list_orders';
    }

    public function getDescription(): string
    {
        return 'List store orders with optional filters by status, customer ID, and pagination.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'description' => 'Filter by order status: pending, processing, completed, cancelled, refunded',
                    'enum' => ['pending', 'processing', 'completed', 'cancelled', 'refunded'],
                ],
                'customer_id' => [
                    'type' => 'integer',
                    'description' => 'Filter orders for a specific customer ID',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of orders to return (1-100)',
                    'default' => 20,
                    'minimum' => 1,
                    'maximum' => 100,
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'Pagination offset',
                    'default' => 0,
                    'minimum' => 0,
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments): ToolResult
    {
        $criteria = [];
        if (isset($arguments['status']) && trim((string)$arguments['status']) !== '') {
            $criteria['status'] = trim((string)$arguments['status']);
        }
        if (isset($arguments['customer_id']) && (int)$arguments['customer_id'] > 0) {
            $criteria['customer_id'] = (int)$arguments['customer_id'];
        }

        $limit = isset($arguments['limit']) ? max(1, min(100, (int)$arguments['limit'])) : 20;
        $offset = isset($arguments['offset']) ? max(0, (int)$arguments['offset']) : 0;

        $orders = $this->orderRepo->findAll($criteria, $limit, $offset);
        $total = $this->orderRepo->count($criteria);

        $summaries = array_map(function (Order $o) {
            return [
                'order_id' => $o->getId(),
                'id' => $o->getId(),
                'order_number' => $o->getOrderNumber(),
                'status' => $o->getStatus()->value,
                'status_label' => $o->getStatus()->label(),
                'grand_total' => $o->getGrandTotal()->getAmount(),
                'formatted_total' => $o->getGrandTotal()->format(),
                'currency' => $o->getCurrency(),
                'customer_id' => $o->getCustomerId(),
                'billing_name' => $o->getBillingAddress()->getFullName(),
                'items_count' => count($o->getItems()),
                'created_at' => $o->getCreatedAt()?->format(DateTimeImmutable::ATOM),
            ];
        }, $orders);

        return ToolResult::json([
            'status' => $criteria['status'] ?? null,
            'total' => $total,
            'count' => count($summaries),
            'limit' => $limit,
            'offset' => $offset,
            'orders' => $summaries,
        ]);
    }
}
