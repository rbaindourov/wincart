<?php

declare(strict_types=1);

namespace WinCart\Mcp\Tools;

use PDO;
use WinCart\Checkout\OrderRepositoryInterface;
use WinCart\Checkout\OrderStateMachine;
use WinCart\Common\OrderStatus;

final class UpdateOrderStatusTool implements ToolInterface
{
    public function __construct(
        private PDO $pdo,
        private OrderStateMachine $orderStateMachine,
        private OrderRepositoryInterface $orderRepo
    ) {
    }

    public function getName(): string
    {
        return 'update_order_status';
    }

    public function getDescription(): string
    {
        return 'Update order status through the OrderStateMachine FSM, handles restocking on cancellation/refund, '
            . 'and records fulfillment notes.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'integer',
                    'description' => 'Order ID to update',
                ],
                'order_id' => [
                    'type' => 'integer',
                    'description' => 'Alternative alias for order ID',
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Target order status: pending, processing, completed, cancelled, refunded',
                    'enum' => ['pending', 'processing', 'completed', 'cancelled', 'refunded'],
                ],
                'note' => [
                    'type' => 'string',
                    'description' => 'Fulfillment or operational note (e.g. carrier tracking number)',
                ],
                'reason' => [
                    'type' => 'string',
                    'description' => 'Alternative alias for operational note',
                ],
                'restock' => [
                    'type' => 'boolean',
                    'description' => 'Whether to restock inventory if cancelling or refunding (default: true)',
                    'default' => true,
                ],
            ],
            'required' => ['status'],
        ];
    }

    public function execute(array $arguments): ToolResult
    {
        $id = (int)($arguments['id'] ?? $arguments['order_id'] ?? 0);
        if ($id <= 0) {
            return ToolResult::text("Error: Missing or invalid 'id' or 'order_id'", isError: true);
        }

        $order = $this->orderRepo->findById($id);
        if ($order === null) {
            return ToolResult::text("Error: Order #{$id} not found", isError: true);
        }

        $statusStr = strtolower(trim((string)($arguments['status'] ?? '')));
        $targetStatus = OrderStatus::tryFrom($statusStr);
        if ($targetStatus === null) {
            return ToolResult::text("Error: Invalid order status '{$statusStr}'", isError: true);
        }

        $note = isset($arguments['note']) || isset($arguments['reason'])
            ? trim((string)($arguments['note'] ?? $arguments['reason']))
            : null;

        $restock = isset($arguments['restock']) ? (bool)$arguments['restock'] : true;

        if ($order->getStatus() === OrderStatus::Pending && $targetStatus === OrderStatus::Completed) {
            $order = $this->orderStateMachine->transition(
                $order,
                OrderStatus::Processing,
                'Auto-advancing to processing before completion',
                false
            );
            $order = $this->orderStateMachine->transition($order, OrderStatus::Completed, $note, $restock);
        } else {
            $order = $this->orderStateMachine->transition($order, $targetStatus, $note, $restock);
        }

        if ($note !== null && $note !== '') {
            $existingNotes = $order->getAdminNotes();
            $newAdminNotes = $existingNotes ? ($existingNotes . "\n" . $note) : $note;
            $stmt = $this->pdo->prepare('UPDATE orders SET admin_notes = :notes WHERE id = :id');
            $stmt->execute([':notes' => $newAdminNotes, ':id' => $id]);
            $order = $this->orderRepo->findById($id) ?? $order;
        }

        return ToolResult::json($order->toArray());
    }
}
