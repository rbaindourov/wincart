<?php

declare(strict_types=1);

namespace WinCart\Checkout;

use WinCart\Common\OrderStatus;

interface OrderRepositoryInterface
{
    public function findById(int $id): ?Order;

    public function findByOrderNumber(string $orderNumber): ?Order;

    /**
     * @param array<string, mixed> $criteria customer_id, status, min_total, max_total
     * @return array<Order>
     */
    public function findAll(array $criteria = [], int $limit = 20, int $offset = 0): array;

    /**
     * @param array<string, mixed> $criteria
     */
    public function count(array $criteria = []): int;

    public function save(Order $order): Order;

    public function updateStatus(int $id, OrderStatus $status): bool;
}
