<?php

declare(strict_types=1);

namespace WinCart\Checkout;

use DateTimeImmutable;
use JsonSerializable;
use WinCart\Common\Address;
use WinCart\Common\Money;
use WinCart\Common\OrderStatus;

final class Order implements JsonSerializable
{
    /**
     * @param array<OrderItem> $items
     */
    public function __construct(
        private int $id,
        private string $orderNumber,
        private ?int $customerId,
        private OrderStatus $status,
        private string $currency,
        private Money $subtotal,
        private Money $discountAmount,
        private Money $taxAmount,
        private float $taxRate,
        private Money $shippingAmount,
        private string $shippingMethod,
        private Money $grandTotal,
        private Address $billingAddress,
        private Address $shippingAddress,
        private string $paymentMethod,
        private ?string $paymentTransactionId = null,
        private ?string $couponCode = null,
        private array $items = [],
        private ?string $customerNotes = null,
        private ?string $adminNotes = null,
        private ?DateTimeImmutable $createdAt = null,
        private ?DateTimeImmutable $updatedAt = null
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getOrderNumber(): string
    {
        return $this->orderNumber;
    }

    public function getCustomerId(): ?int
    {
        return $this->customerId;
    }

    public function getStatus(): OrderStatus
    {
        return $this->status;
    }

    public function setStatus(OrderStatus $status): void
    {
        $this->status = $status;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getSubtotal(): Money
    {
        return $this->subtotal;
    }

    public function getDiscountAmount(): Money
    {
        return $this->discountAmount;
    }

    public function getTaxAmount(): Money
    {
        return $this->taxAmount;
    }

    public function getTaxRate(): float
    {
        return $this->taxRate;
    }

    public function getShippingAmount(): Money
    {
        return $this->shippingAmount;
    }

    public function getShippingMethod(): string
    {
        return $this->shippingMethod;
    }

    public function getGrandTotal(): Money
    {
        return $this->grandTotal;
    }

    public function getBillingAddress(): Address
    {
        return $this->billingAddress;
    }

    public function getShippingAddress(): Address
    {
        return $this->shippingAddress;
    }

    public function getPaymentMethod(): string
    {
        return $this->paymentMethod;
    }

    public function getPaymentTransactionId(): ?string
    {
        return $this->paymentTransactionId;
    }

    public function getCouponCode(): ?string
    {
        return $this->couponCode;
    }

    /**
     * @return array<OrderItem>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function getCustomerNotes(): ?string
    {
        return $this->customerNotes;
    }

    public function getAdminNotes(): ?string
    {
        return $this->adminNotes;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'order_id' => $this->id,
            'id' => $this->id,
            'order_number' => $this->orderNumber,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'currency' => $this->currency,
            'subtotal' => $this->subtotal->getAmount(),
            'discount_amount' => $this->discountAmount->getAmount(),
            'coupon_code' => $this->couponCode,
            'tax_amount' => $this->taxAmount->getAmount(),
            'tax_rate' => $this->taxRate,
            'shipping_amount' => $this->shippingAmount->getAmount(),
            'shipping_method' => $this->shippingMethod,
            'grand_total' => $this->grandTotal->getAmount(),
            'formatted_total' => $this->grandTotal->format(),
            'payment_method' => $this->paymentMethod,
            'payment_transaction_id' => $this->paymentTransactionId,
            'billing_address' => $this->billingAddress->toArray(),
            'shipping_address' => $this->shippingAddress->toArray(),
            'items' => array_map(fn(OrderItem $i) => $i->toArray(), $this->items),
            'customer_notes' => $this->customerNotes,
            'admin_notes' => $this->adminNotes,
            'created_at' => $this->createdAt?->format(DateTimeImmutable::ATOM),
            'updated_at' => $this->updatedAt?->format(DateTimeImmutable::ATOM),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
