<?php

declare(strict_types=1);

namespace WinCart\Api\Controllers;

use PDO;
use Throwable;
use WinCart\Checkout\Exceptions\InvalidOrderStateTransitionException;
use WinCart\Checkout\OrderRepositoryInterface;
use WinCart\Checkout\OrderStateMachine;
use WinCart\Checkout\PdoOrderRepository;
use WinCart\Common\OrderStatus;
use WinCart\Http\ProblemDetails;
use WinCart\Tests\E2E\Support\HttpResponse;

final class OrderApiController
{
    private OrderRepositoryInterface $orderRepository;
    private OrderStateMachine $stateMachine;

    public function __construct(private PDO $pdo)
    {
        $this->orderRepository = new PdoOrderRepository($this->pdo);
        $this->stateMachine = new OrderStateMachine($this->pdo, $this->orderRepository);
    }

    /**
     * @param array<string, mixed> $queryParams
     */
    public function list(array $queryParams, ?int $authCustomerId, bool $isAdmin): HttpResponse
    {
        $status = isset($queryParams['status']) ? (string)$queryParams['status'] : null;
        $limit = isset($queryParams['limit']) ? max(1, min(100, (int)$queryParams['limit'])) : 20;
        $page = isset($queryParams['page']) ? max(1, (int)$queryParams['page']) : 1;
        $offset = isset($queryParams['offset']) ? max(0, (int)$queryParams['offset']) : (($page - 1) * $limit);

        $conditions = ['1=1'];
        $params = [];

        // Customer scoping: non-admins are restricted to their own customer_id
        if (!$isAdmin) {
            if ($authCustomerId === null) {
                return ProblemDetails::create(401, 'Unauthorized', 'Authentication required');
            }
            $conditions[] = 'customer_id = :cust_id';
            $params[':cust_id'] = $authCustomerId;
        } elseif (isset($queryParams['customer_id'])) {
            $conditions[] = 'customer_id = :cust_id';
            $params[':cust_id'] = (int)$queryParams['customer_id'];
        }

        if ($status !== null && $status !== '') {
            $conditions[] = 'status = :status';
            $params[':status'] = $status;
        }

        $whereClause = ' WHERE ' . implode(' AND ', $conditions);
        $sql = "SELECT * FROM orders {$whereClause} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $countSql = "SELECT COUNT(*) FROM orders {$whereClause}";
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        return new HttpResponse(
            200,
            (string)json_encode([
                'data' => $orders,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
            ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            ['Content-Type' => ['application/json; charset=UTF-8']]
        );
    }

    public function show(int $id, ?int $authCustomerId, bool $isAdmin): HttpResponse
    {
        $order = $this->orderRepository->findById($id);
        if ($order === null) {
            return ProblemDetails::create(404, 'Not Found', "Order #{$id} not found");
        }

        // Customer isolation guard
        if (!$isAdmin && $order->getCustomerId() !== $authCustomerId) {
            return ProblemDetails::create(403, 'Forbidden', 'Access denied to requested order resource');
        }

        return new HttpResponse(
            200,
            (string)json_encode($order->toArray(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            ['Content-Type' => ['application/json; charset=UTF-8']]
        );
    }

    public function updateStatus(
        int $id,
        string $statusString,
        ?int $authCustomerId = null,
        bool $isAdmin = true
    ): HttpResponse {
        $order = $this->orderRepository->findById($id);
        if ($order === null) {
            return ProblemDetails::create(404, 'Not Found', "Order #{$id} not found");
        }

        // Customer isolation guard
        if (!$isAdmin && $order->getCustomerId() !== $authCustomerId) {
            return ProblemDetails::create(403, 'Forbidden', 'Access denied to requested order resource');
        }

        $targetStatus = OrderStatus::tryFrom(strtolower($statusString));
        if ($targetStatus === null) {
            return ProblemDetails::create(422, 'Unprocessable Entity', "Invalid order status '{$statusString}'");
        }

        try {
            $updated = $this->stateMachine->transition($order, $targetStatus);
            return new HttpResponse(
                200,
                (string)json_encode($updated->toArray(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
                ['Content-Type' => ['application/json; charset=UTF-8']]
            );
        } catch (InvalidOrderStateTransitionException $e) {
            return ProblemDetails::create(422, 'Invalid Order State Transition', $e->getMessage());
        }
    }

    public function cancel(
        int $id,
        ?string $reason = null,
        bool $restock = false,
        ?int $authCustomerId = null,
        bool $isAdmin = true
    ): HttpResponse {
        $order = $this->orderRepository->findById($id);
        if ($order === null) {
            return ProblemDetails::create(404, 'Not Found', "Order #{$id} not found");
        }

        // Customer isolation guard
        if (!$isAdmin && $order->getCustomerId() !== $authCustomerId) {
            return ProblemDetails::create(403, 'Forbidden', 'Access denied to requested order resource');
        }

        try {
            $updated = $this->stateMachine->transition($order, OrderStatus::Cancelled, $reason, $restock);
            return new HttpResponse(
                200,
                (string)json_encode($updated->toArray(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
                ['Content-Type' => ['application/json; charset=UTF-8']]
            );
        } catch (InvalidOrderStateTransitionException $e) {
            return ProblemDetails::create(422, 'Invalid Order State Transition', $e->getMessage());
        }
    }

    /**
     * @param list<array{product_id: int, quantity: int, variation_id?: ?int}>|null $items
     */
    public function refund(
        int $id,
        ?array $items = null,
        ?string $reason = null,
        bool $restock = false,
        ?int $authCustomerId = null,
        bool $isAdmin = true
    ): HttpResponse {
        $order = $this->orderRepository->findById($id);
        if ($order === null) {
            return ProblemDetails::create(404, 'Not Found', "Order #{$id} not found");
        }

        // Customer isolation guard
        if (!$isAdmin && $order->getCustomerId() !== $authCustomerId) {
            return ProblemDetails::create(403, 'Forbidden', 'Access denied to requested order resource');
        }

        try {
            $updated = $this->stateMachine->transition($order, OrderStatus::Refunded, $reason, $restock, $items);
            return new HttpResponse(
                200,
                (string)json_encode($updated->toArray(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
                ['Content-Type' => ['application/json; charset=UTF-8']]
            );
        } catch (InvalidOrderStateTransitionException $e) {
            return ProblemDetails::create(422, 'Invalid Order State Transition', $e->getMessage());
        }
    }
}
