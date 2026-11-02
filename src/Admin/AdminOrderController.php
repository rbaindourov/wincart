<?php

declare(strict_types=1);

namespace WinCart\Admin;

use PDO;
use WinCart\Checkout\OrderRepositoryInterface;
use WinCart\Checkout\OrderStateMachine;
use WinCart\Checkout\PdoOrderRepository;
use WinCart\Common\OrderStatus;

final class AdminOrderController
{
    private OrderRepositoryInterface $orderRepository;
    private OrderStateMachine $stateMachine;

    public function __construct(private PDO $pdo)
    {
        $this->orderRepository = new PdoOrderRepository($this->pdo);
        $this->stateMachine = new OrderStateMachine($this->pdo, $this->orderRepository);
    }

    public function index(): string
    {
        $stmt = $this->pdo->query('SELECT * FROM orders ORDER BY id DESC LIMIT 50');
        $orders = $stmt->fetchAll();

        ob_start();
        $templatePath = dirname(__DIR__, 2) . '/templates/admin/orders.php';
        if (file_exists($templatePath)) {
            require $templatePath;
        } else {
            echo '<h1>Orders</h1>';
        }
        return (string)ob_get_clean();
    }

    public function show(int $id): string
    {
        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $order = $stmt->fetch();

        $itemStmt = $this->pdo->prepare('SELECT * FROM order_items WHERE order_id = :id');
        $itemStmt->execute([':id' => $id]);
        $items = $itemStmt->fetchAll();

        ob_start();
        $templatePath = dirname(__DIR__, 2) . '/templates/admin/order-detail.php';
        if (file_exists($templatePath)) {
            require $templatePath;
        } else {
            echo '<h1>Order Details</h1>';
        }
        return (string)ob_get_clean();
    }

    /**
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    public function apiList(array $queryParams = []): array
    {
        $status = isset($queryParams['status']) ? (string)$queryParams['status'] : null;
        $limit = isset($queryParams['limit']) ? max(1, min(100, (int)$queryParams['limit'])) : 20;
        $page = isset($queryParams['page']) ? max(1, (int)$queryParams['page']) : 1;
        $offset = ($page - 1) * $limit;

        $sql = 'SELECT * FROM orders';
        $params = [];
        if ($status !== null && $status !== '') {
            $sql .= ' WHERE status = :status';
            $params[':status'] = $status;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . $limit . ' OFFSET ' . $offset;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $orders = $stmt->fetchAll();

        $countSql = 'SELECT COUNT(*) FROM orders';
        if ($status !== null && $status !== '') {
            $countSql .= ' WHERE status = :status';
        }
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        return [
            'data' => $orders,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function apiShow(int $id): ?array
    {
        $order = $this->orderRepository->findById($id);
        return $order?->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function apiUpdateStatus(int $id, string $targetStatusString): array
    {
        $order = $this->orderRepository->findById($id);
        if ($order === null) {
            throw new \WinCart\Checkout\Exceptions\OrderNotFoundException("Order #{$id} not found");
        }

        $targetStatus = OrderStatus::from($targetStatusString);
        $updatedOrder = $this->stateMachine->transition($order, $targetStatus);
        return $updatedOrder->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function apiCancel(int $id, ?string $reason = null, bool $restock = false): array
    {
        $order = $this->orderRepository->findById($id);
        if ($order === null) {
            throw new \WinCart\Checkout\Exceptions\OrderNotFoundException("Order #{$id} not found");
        }

        $updatedOrder = $this->stateMachine->transition(
            $order,
            OrderStatus::Cancelled,
            $reason,
            $restock
        );
        return $updatedOrder->toArray();
    }

    /**
     * @param list<array{product_id: int, quantity: int}>|null $items
     * @return array<string, mixed>
     */
    public function apiRefund(
        int $id,
        ?array $items = null,
        ?string $reason = null,
        bool $restock = false
    ): array {
        $order = $this->orderRepository->findById($id);
        if ($order === null) {
            throw new \WinCart\Checkout\Exceptions\OrderNotFoundException("Order #{$id} not found");
        }

        $updatedOrder = $this->stateMachine->transition(
            $order,
            OrderStatus::Refunded,
            $reason,
            $restock,
            $items
        );
        return $updatedOrder->toArray();
    }
}
