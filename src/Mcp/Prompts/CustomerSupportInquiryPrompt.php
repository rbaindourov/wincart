<?php

declare(strict_types=1);

namespace WinCart\Mcp\Prompts;

use WinCart\Checkout\Order;
use WinCart\Checkout\OrderItem;
use WinCart\Checkout\OrderRepositoryInterface;

final class CustomerSupportInquiryPrompt implements PromptInterface
{
    public function __construct(private OrderRepositoryInterface $orderRepo)
    {
    }

    public function getName(): string
    {
        return 'customer_support_inquiry';
    }

    public function getDescription(): string
    {
        return 'Generates support agent prompt given an order number or customer email';
    }

    public function getArguments(): array
    {
        return [
            [
                'name' => 'order_number',
                'description' => 'Order number string (e.g. "WC-001")',
                'required' => false,
            ],
            [
                'name' => 'customer_email',
                'description' => 'Customer email address',
                'required' => false,
            ],
        ];
    }

    public function get(array $arguments): PromptResult
    {
        $orderNum = isset($arguments['order_number']) ? trim((string)$arguments['order_number']) : null;
        $order = null;

        if ($orderNum !== null && $orderNum !== '') {
            $order = $this->orderRepo->findByOrderNumber($orderNum);
        }

        if ($order === null) {
            $orders = $this->orderRepo->findAll([], 1, 0);
            $order = $orders[0] ?? null;
        }

        if ($order instanceof Order) {
            $itemsList = implode("\n", array_map(function (OrderItem $item) {
                return sprintf(
                    '- %dx %s (SKU: %s) — %s',
                    $item->getQuantity(),
                    $item->getProductName(),
                    $item->getProductSku(),
                    $item->getUnitPrice()->format()
                );
            }, $order->getItems()));

            $dest = $order->getShippingAddress()->getCity() . ', '
                . $order->getShippingAddress()->getState() . ' '
                . $order->getShippingAddress()->getPostalCode();

            $text = <<<PROMPT
# Customer Support Context

## Order Information
- Order Number: {$order->getOrderNumber()}
- Status: {$order->getStatus()->label()}
- Order Total: {$order->getGrandTotal()->format()}
- Date Placed: {$order->getCreatedAt()?->format('Y-m-d H:i')}
- Shipping Method: {$order->getShippingMethod()}
- Recipient: {$order->getShippingAddress()->getFullName()}
- Shipping Destination: {$dest}

## Purchased Items
{$itemsList}

## Guidance for AI Support Agent
1. Greet the customer warmly and verify identity.
2. Clearly explain the current order status ({$order->getStatus()->label()}).
3. For cancellations or returns, inform customer of our standard store return policy.
PROMPT;
        } else {
            $text = "No order record found. Please ask the customer to provide their order number "
                . "or the email address used during checkout.";
        }

        return new PromptResult(
            description: "Contextual customer support inquiry response prompt",
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
