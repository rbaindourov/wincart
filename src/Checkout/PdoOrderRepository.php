<?php

declare(strict_types=1);

namespace WinCart\Checkout;

use DateTimeImmutable;
use PDO;
use WinCart\Common\Address;
use WinCart\Common\Money;
use WinCart\Common\OrderStatus;

final class PdoOrderRepository implements OrderRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findById(int $id): ?Order
    {
        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrateOrder($row) : null;
    }

    public function findByOrderNumber(string $orderNumber): ?Order
    {
        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE order_number = :num');
        $stmt->execute([':num' => $orderNumber]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrateOrder($row) : null;
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array<Order>
     */
    public function findAll(array $criteria = [], int $limit = 20, int $offset = 0): array
    {
        [$whereSql, $params] = $this->buildWhereClause($criteria);
        $sql = "SELECT * FROM orders {$whereSql} ORDER BY id DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $r) => $this->hydrateOrder($r), $rows);
    }

    /**
     * @param array<string, mixed> $criteria
     */
    public function count(array $criteria = []): int
    {
        [$whereSql, $params] = $this->buildWhereClause($criteria);
        $sql = "SELECT COUNT(*) FROM orders {$whereSql}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    public function save(Order $order): Order
    {
        $now = date('Y-m-d H:i:s');
        $b = $order->getBillingAddress();
        $s = $order->getShippingAddress();

        if ($order->getId() <= 0) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO orders (
                    order_number, customer_id, status, currency, subtotal, discount_amount,
                    coupon_code, tax_amount, tax_rate, shipping_amount, shipping_method, grand_total,
                    billing_first_name, billing_last_name, billing_company, billing_address_1,
                    billing_address_2, billing_city, billing_state, billing_postal_code, billing_country,
                    billing_phone, shipping_first_name, shipping_last_name, shipping_company,
                    shipping_address_1, shipping_address_2, shipping_city, shipping_state,
                    shipping_postal_code, shipping_country, shipping_phone, payment_method,
                    payment_transaction_id, customer_notes, admin_notes, created_at, updated_at
                ) VALUES (
                    :order_number, :customer_id, :status, :currency, :subtotal, :discount_amount,
                    :coupon_code, :tax_amount, :tax_rate, :shipping_amount, :shipping_method, :grand_total,
                    :bf_name, :bl_name, :b_comp, :ba1, :ba2, :b_city, :b_state, :b_zip, :b_country,
                    :b_phone, :sf_name, :sl_name, :s_comp, :sa1, :sa2, :s_city, :s_state,
                    :s_zip, :s_country, :s_phone, :payment_method,
                    :payment_transaction_id, :customer_notes, :admin_notes, :created_at, :updated_at
                )'
            );
            $stmt->execute([
                ':order_number' => $order->getOrderNumber(),
                ':customer_id' => $order->getCustomerId(),
                ':status' => $order->getStatus()->value,
                ':currency' => $order->getCurrency(),
                ':subtotal' => $order->getSubtotal()->getAmount(),
                ':discount_amount' => $order->getDiscountAmount()->getAmount(),
                ':coupon_code' => $order->getCouponCode(),
                ':tax_amount' => $order->getTaxAmount()->getAmount(),
                ':tax_rate' => $order->getTaxRate(),
                ':shipping_amount' => $order->getShippingAmount()->getAmount(),
                ':shipping_method' => $order->getShippingMethod(),
                ':grand_total' => $order->getGrandTotal()->getAmount(),
                ':bf_name' => $b->firstName,
                ':bl_name' => $b->lastName,
                ':b_comp' => $b->company,
                ':ba1' => $b->address1,
                ':ba2' => $b->address2,
                ':b_city' => $b->city,
                ':b_state' => $b->state,
                ':b_zip' => $b->postalCode,
                ':b_country' => $b->country,
                ':b_phone' => $b->phone,
                ':sf_name' => $s->firstName,
                ':sl_name' => $s->lastName,
                ':s_comp' => $s->company,
                ':sa1' => $s->address1,
                ':sa2' => $s->address2,
                ':s_city' => $s->city,
                ':s_state' => $s->state,
                ':s_zip' => $s->postalCode,
                ':s_country' => $s->country,
                ':s_phone' => $s->phone,
                ':payment_method' => $order->getPaymentMethod(),
                ':payment_transaction_id' => $order->getPaymentTransactionId(),
                ':customer_notes' => $order->getCustomerNotes(),
                ':admin_notes' => $order->getAdminNotes(),
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);

            $id = (int)$this->pdo->lastInsertId();

            // Insert line items
            $itemStmt = $this->pdo->prepare(
                'INSERT INTO order_items (
                    order_id, product_id, variation_id, product_name, product_sku,
                    attributes_json, unit_price, quantity, line_subtotal, created_at
                ) VALUES (
                    :oid, :pid, :vid, :pname, :sku, :attrs, :uprice, :qty, :subtotal, :created
                )'
            );

            foreach ($order->getItems() as $item) {
                $itemStmt->execute([
                    ':oid' => $id,
                    ':pid' => $item->getProductId(),
                    ':vid' => $item->getVariationId(),
                    ':pname' => $item->getProductName(),
                    ':sku' => $item->getProductSku(),
                    ':attrs' => $item->getAttributesJson(),
                    ':uprice' => $item->getUnitPrice()->getAmount(),
                    ':qty' => $item->getQuantity(),
                    ':subtotal' => $item->getLineSubtotal()->getAmount(),
                    ':created' => $now,
                ]);
            }

            return $this->findById($id) ?? $order;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE orders SET status = :status, updated_at = :updated WHERE id = :id'
        );
        $stmt->execute([
            ':status' => $order->getStatus()->value,
            ':updated' => $now,
            ':id' => $order->getId(),
        ]);

        return $this->findById($order->getId()) ?? $order;
    }

    public function updateStatus(int $id, OrderStatus $status): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE orders SET status = :status, updated_at = :now WHERE id = :id'
        );
        $stmt->execute([
            ':status' => $status->value,
            ':now' => date('Y-m-d H:i:s'),
            ':id' => $id,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildWhereClause(array $criteria): array
    {
        $where = ['1=1'];
        $params = [];

        if (isset($criteria['status']) && trim((string)$criteria['status']) !== '') {
            $where[] = 'status = :status';
            $params[':status'] = (string)$criteria['status'];
        }

        if (isset($criteria['customer_id'])) {
            $where[] = 'customer_id = :cid';
            $params[':cid'] = (int)$criteria['customer_id'];
        }

        return ['WHERE ' . implode(' AND ', $where), $params];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateOrder(array $row): Order
    {
        $id = (int)$row['id'];
        $itemStmt = $this->pdo->prepare('SELECT * FROM order_items WHERE order_id = :id ORDER BY id ASC');
        $itemStmt->execute([':id' => $id]);
        $itemRows = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

        $currency = (string)($row['currency'] ?? 'USD');
        $items = [];
        foreach ($itemRows as $ir) {
            $items[] = new OrderItem(
                id: (int)$ir['id'],
                orderId: $id,
                productId: isset($ir['product_id']) && $ir['product_id'] !== null ? (int)$ir['product_id'] : null,
                productName: (string)$ir['product_name'],
                productSku: (string)$ir['product_sku'],
                unitPrice: new Money((int)$ir['unit_price'], $currency),
                quantity: (int)$ir['quantity'],
                lineSubtotal: new Money((int)$ir['line_subtotal'], $currency),
                variationId: isset($ir['variation_id']) && $ir['variation_id'] !== null
                    ? (int)$ir['variation_id']
                    : null,
                attributesJson: isset($ir['attributes_json']) ? (string)$ir['attributes_json'] : null,
                createdAt: isset($ir['created_at']) ? new DateTimeImmutable((string)$ir['created_at']) : null
            );
        }

        $billingAddress = new Address(
            firstName: (string)$row['billing_first_name'],
            lastName: (string)$row['billing_last_name'],
            address1: (string)$row['billing_address_1'],
            address2: isset($row['billing_address_2']) ? (string)$row['billing_address_2'] : null,
            city: (string)$row['billing_city'],
            state: (string)$row['billing_state'],
            postalCode: (string)$row['billing_postal_code'],
            country: (string)$row['billing_country'],
            phone: isset($row['billing_phone']) ? (string)$row['billing_phone'] : null,
            company: isset($row['billing_company']) ? (string)$row['billing_company'] : null
        );

        $shippingAddress = new Address(
            firstName: (string)$row['shipping_first_name'],
            lastName: (string)$row['shipping_last_name'],
            address1: (string)$row['shipping_address_1'],
            address2: isset($row['shipping_address_2']) ? (string)$row['shipping_address_2'] : null,
            city: (string)$row['shipping_city'],
            state: (string)$row['shipping_state'],
            postalCode: (string)$row['shipping_postal_code'],
            country: (string)$row['shipping_country'],
            phone: isset($row['shipping_phone']) ? (string)$row['shipping_phone'] : null,
            company: isset($row['shipping_company']) ? (string)$row['shipping_company'] : null
        );

        $status = OrderStatus::tryFrom((string)$row['status']) ?? OrderStatus::Pending;

        return new Order(
            id: $id,
            orderNumber: (string)$row['order_number'],
            customerId: isset($row['customer_id']) && $row['customer_id'] !== null ? (int)$row['customer_id'] : null,
            status: $status,
            currency: $currency,
            subtotal: new Money((int)$row['subtotal'], $currency),
            discountAmount: new Money((int)($row['discount_amount'] ?? 0), $currency),
            taxAmount: new Money((int)($row['tax_amount'] ?? 0), $currency),
            taxRate: (float)($row['tax_rate'] ?? 0.0),
            shippingAmount: new Money((int)($row['shipping_amount'] ?? 0), $currency),
            shippingMethod: (string)($row['shipping_method'] ?? 'standard_ground'),
            grandTotal: new Money((int)$row['grand_total'], $currency),
            billingAddress: $billingAddress,
            shippingAddress: $shippingAddress,
            paymentMethod: (string)$row['payment_method'],
            paymentTransactionId: isset($row['payment_transaction_id']) ? (string)$row['payment_transaction_id'] : null,
            couponCode: isset($row['coupon_code']) ? (string)$row['coupon_code'] : null,
            items: $items,
            customerNotes: isset($row['customer_notes']) ? (string)$row['customer_notes'] : null,
            adminNotes: isset($row['admin_notes']) ? (string)$row['admin_notes'] : null,
            createdAt: isset($row['created_at']) ? new DateTimeImmutable((string)$row['created_at']) : null,
            updatedAt: isset($row['updated_at']) ? new DateTimeImmutable((string)$row['updated_at']) : null
        );
    }
}
