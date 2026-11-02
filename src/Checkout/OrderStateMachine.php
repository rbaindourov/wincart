<?php

declare(strict_types=1);

namespace WinCart\Checkout;

use PDO;
use Throwable;
use WinCart\Catalog\Repositories\PdoProductRepository;
use WinCart\Catalog\Repositories\ProductRepositoryInterface;
use WinCart\Checkout\Exceptions\InvalidOrderStateTransitionException;
use WinCart\Common\OrderStatus;

final class OrderStateMachine
{
    private ProductRepositoryInterface $productRepo;

    public function __construct(
        private PDO $pdo,
        private ?OrderRepositoryInterface $orderRepo = null,
        ?ProductRepositoryInterface $productRepo = null
    ) {
        $this->orderRepo = $this->orderRepo ?? new PdoOrderRepository($pdo);
        $this->productRepo = $productRepo ?? new PdoProductRepository($pdo);
    }

    public function canTransition(Order $order, OrderStatus $targetStatus): bool
    {
        return $order->getStatus()->canTransitionTo($targetStatus);
    }

    /**
     * Execute order status transition with atomic restock support.
     *
     * @param Order $order
     * @param OrderStatus $targetStatus
     * @param string|null $reason
     * @param bool $restock Whether to restore inventory to catalog
     * @param list<array{product_id: int, quantity: int, variation_id?: ?int}>|null $restockItems
     * @return Order
     * @throws InvalidOrderStateTransitionException
     * @throws Throwable
     */
    public function transition(
        Order $order,
        OrderStatus $targetStatus,
        ?string $reason = null,
        bool $restock = false,
        ?array $restockItems = null
    ): Order {
        // Idempotency: self-transition is a valid legal no-op
        if ($order->getStatus() === $targetStatus) {
            return $order;
        }

        // FSM guard validation
        if (!$order->getStatus()->canTransitionTo($targetStatus)) {
            throw new InvalidOrderStateTransitionException(
                sprintf(
                    'Cannot transition order #%d from status "%s" to "%s"',
                    $order->getId(),
                    $order->getStatus()->value,
                    $targetStatus->value
                )
            );
        }

        $this->pdo->beginTransaction();
        try {
            $now = date('Y-m-d H:i:s');
            $stmt = $this->pdo->prepare('UPDATE orders SET status = :status, updated_at = :now WHERE id = :id');
            $stmt->execute([
                ':status' => $targetStatus->value,
                ':now' => $now,
                ':id' => $order->getId(),
            ]);

            // Handle inventory restocking if requested on cancellation or refund
            if ($restock && ($targetStatus === OrderStatus::Cancelled || $targetStatus === OrderStatus::Refunded)) {
                $reasonTag = $targetStatus === OrderStatus::Cancelled ? 'cancellation' : 'refund';

                if ($restockItems !== null) {
                    foreach ($restockItems as $rItem) {
                        $pId = (int)$rItem['product_id'];
                        $vId = isset($rItem['variation_id']) ? (int)$rItem['variation_id'] : null;
                        $qty = (int)$rItem['quantity'];
                        $this->productRepo->adjustStock($pId, $qty, $reasonTag, $vId);
                    }
                } else {
                    foreach ($order->getItems() as $item) {
                        $pId = $item->getProductId();
                        if ($pId !== null) {
                            $this->productRepo->adjustStock(
                                $pId,
                                $item->getQuantity(),
                                $reasonTag,
                                $item->getVariationId()
                            );
                        }
                    }
                }
            }

            $this->pdo->commit();
            $order->setStatus($targetStatus);
            return $order;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
