<?php

declare(strict_types=1);

namespace WinCart\Checkout;

use DateTimeImmutable;
use PDO;
use Throwable;
use WinCart\Cart\Models\Cart;
use WinCart\Cart\Pricing\Coupon;
use WinCart\Cart\Pricing\CouponCalculator;
use WinCart\Cart\Pricing\ShippingCalculator;
use WinCart\Cart\Pricing\TaxEngine;
use WinCart\Cart\Services\CartManagerInterface;
use WinCart\Catalog\Exceptions\InsufficientStockException;
use WinCart\Catalog\Exceptions\ProductNotFoundException;
use WinCart\Catalog\Repositories\PdoProductRepository;
use WinCart\Catalog\Repositories\ProductRepositoryInterface;
use WinCart\Checkout\Exceptions\EmptyCartException;
use WinCart\Checkout\Exceptions\PaymentFailedException;
use WinCart\Common\Address;
use WinCart\Common\Money;
use WinCart\Common\OrderStatus;
use WinCart\Payment\MockPaymentGateway;
use WinCart\Payment\PaymentGatewayRegistry;
use WinCart\Payment\PaymentRequest;

final class CheckoutService implements CheckoutServiceInterface
{
    private ProductRepositoryInterface $productRepo;
    private OrderRepositoryInterface $orderRepo;
    private PaymentGatewayRegistry $paymentRegistry;
    private TaxEngine $taxEngine;
    private ShippingCalculator $shippingCalculator;

    private ?CartManagerInterface $cartManager;

    public function __construct(
        private PDO $pdo,
        ?OrderRepositoryInterface $orderRepo = null,
        ?ProductRepositoryInterface $productRepo = null,
        ?PaymentGatewayRegistry $paymentRegistry = null,
        ?CartManagerInterface $cartManager = null,
        ?TaxEngine $taxEngine = null,
        ?ShippingCalculator $shippingCalculator = null
    ) {
        $this->orderRepo = $orderRepo ?? new PdoOrderRepository($pdo);
        $this->productRepo = $productRepo ?? new PdoProductRepository($pdo);
        $this->cartManager = $cartManager;

        if ($paymentRegistry !== null) {
            $this->paymentRegistry = $paymentRegistry;
        } else {
            $this->paymentRegistry = new PaymentGatewayRegistry();
            $this->paymentRegistry->register(new MockPaymentGateway());
        }

        $this->taxEngine = $taxEngine ?? new TaxEngine($pdo);
        $this->shippingCalculator = $shippingCalculator ?? new ShippingCalculator($pdo);
    }

    /**
     * @param Cart|null $cart
     * @param array<string, mixed> $checkoutData
     * @return Order
     * @throws EmptyCartException
     * @throws InsufficientStockException
     * @throws PaymentFailedException
     * @throws Throwable
     */
    public function submitOrder(?Cart $cart, array $checkoutData): Order
    {
        // If cart is null or empty, attempt reload via cartManager
        if ($this->cartManager !== null) {
            if ($cart === null && isset($checkoutData['cart_token'])) {
                $cart = $this->cartManager->getCartByToken((string)$checkoutData['cart_token']);
            } elseif ($cart !== null && $cart->isEmpty() && $cart->getToken() !== '') {
                $cart = $this->cartManager->getCartByToken($cart->getToken()) ?? $cart;
            }
        }

        // 1. Resolve line items
        /** @var list<array{product_id: int, quantity: int, variation_id: ?int}> $normalizedItems */
        $normalizedItems = [];

        if ($cart !== null && !$cart->isEmpty()) {
            foreach ($cart->getItems() as $item) {
                $normalizedItems[] = [
                    'product_id' => $item->getProductId(),
                    'quantity' => $item->getQuantity(),
                    'variation_id' => $item->getVariationId(),
                ];
            }
        } elseif (isset($checkoutData['items']) && is_array($checkoutData['items']) && !empty($checkoutData['items'])) {
            foreach ($checkoutData['items'] as $item) {
                $normalizedItems[] = [
                    'product_id' => (int)$item['product_id'],
                    'quantity' => (int)($item['quantity'] ?? 1),
                    'variation_id' => isset($item['variation_id']) ? (int)$item['variation_id'] : null,
                ];
            }
        } else {
            throw new EmptyCartException('Cart cannot be empty for checkout submission');
        }

        // 2. Resolve addresses
        $billingAddress = $this->resolveAddress($checkoutData['billing_address'] ?? null);
        $shippingAddress = $this->resolveAddress($checkoutData['shipping_address'] ?? null) ?? $billingAddress;

        $this->pdo->beginTransaction();
        try {
            // 3. Atomic stock decrement for each line item
            /** @var list<OrderItem> $orderItems */
            $orderItems = [];
            $subtotalAmount = 0;
            $currency = 'USD';

            foreach ($normalizedItems as $itemSpec) {
                $pId = $itemSpec['product_id'];
                $vId = $itemSpec['variation_id'];
                $qty = $itemSpec['quantity'];

                // Deduct stock atomically; throws InsufficientStockException if deficit
                $this->productRepo->adjustStock($pId, -$qty, 'checkout', $vId);

                // Fetch current snapshot details
                if ($vId !== null) {
                    $variation = $this->productRepo->findVariationById($vId);
                    $product = $this->productRepo->findById($pId);
                    $pName = $product ? $product->getName() : 'Product #' . $pId;
                    $pSku = $variation ? $variation->getSku() : 'SKU-' . $pId;
                    $unitPrice = $variation ? $variation->getEffectivePrice() : new Money(0, $currency);
                    $attrsJson = $variation ? json_encode($variation->getAttributeMap()) : null;
                } else {
                    $product = $this->productRepo->findById($pId);
                    if ($product === null) {
                        throw new ProductNotFoundException("Product #{$pId} not found");
                    }
                    $pName = $product->getName();
                    $pSku = $product->getSku();
                    $unitPrice = $product->getEffectivePrice();
                    $attrsJson = null;
                }

                $currency = $unitPrice->getCurrency();
                $lineTotal = $unitPrice->multiply($qty);
                $subtotalAmount += $lineTotal->getAmount();

                $orderItems[] = new OrderItem(
                    id: null,
                    orderId: 0,
                    productId: $pId,
                    productName: $pName,
                    productSku: $pSku,
                    unitPrice: $unitPrice,
                    quantity: $qty,
                    lineSubtotal: $lineTotal,
                    variationId: $vId,
                    attributesJson: is_string($attrsJson) ? $attrsJson : null,
                    createdAt: new DateTimeImmutable()
                );
            }

            $subtotal = new Money($subtotalAmount, $currency);

            // 4. Coupon & Discount
            $couponCode = $checkoutData['coupon_code'] ?? ($cart?->getCouponCode() ?? null);
            $discount = Money::zero($currency);

            if ($couponCode !== null) {
                $coupon = $this->findCoupon((string)$couponCode);
                if ($coupon !== null && $coupon->isValid($subtotal)) {
                    $discount = CouponCalculator::calculateDiscount($coupon, $subtotal);
                }
            }

            $discountedSubtotal = $subtotal->subtract($discount);

            // 5. Tax calculation on discounted subtotal
            $taxResult = $this->taxEngine->calculateTax(
                $discountedSubtotal,
                $shippingAddress->state,
                $shippingAddress->country
            );

            // 6. Shipping calculation
            $shippingMethod = (string)($checkoutData['shipping_method'] ?? 'standard_ground');
            if ($cart !== null) {
                $shippingResult = $this->shippingCalculator->calculateShipping(
                    $cart,
                    $discountedSubtotal,
                    $shippingMethod,
                    $shippingAddress->state
                );
            } else {
                // Free shipping threshold >= $150.00
                $shippingFee = $discountedSubtotal->getAmount() >= 15000 ? 0 : 750;
                $shippingResult = new \WinCart\Cart\Pricing\ShippingResult(
                    new Money($shippingFee, $currency),
                    $shippingMethod,
                    $shippingFee === 0
                );
            }

            // 7. Grand total
            $grandTotal = $discountedSubtotal
                ->add($taxResult->getTaxAmount())
                ->add($shippingResult->getShippingAmount());

            // 8. Payment execution
            $paymentMethod = (string)($checkoutData['payment_method'] ?? 'mock_gateway');
            $paymentToken = (string)($checkoutData['payment_token'] ?? $checkoutData['token'] ?? 'tok_mock_success');

            $orderNumber = 'WC-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $gateway = $this->paymentRegistry->get($paymentMethod);
            $paymentRequest = new PaymentRequest($grandTotal, $paymentToken, $orderNumber, $billingAddress);
            $paymentResult = $gateway->processPayment($paymentRequest);

            if (!$paymentResult->isSuccessful()) {
                throw new PaymentFailedException($paymentResult->getErrorMessage() ?? 'Payment failed');
            }

            // 9. Persist Order
            $customerId = isset($checkoutData['customer_id']) && $checkoutData['customer_id'] !== null
                ? (int)$checkoutData['customer_id']
                : $cart?->getCustomerId();

            $order = new Order(
                id: 0,
                orderNumber: $orderNumber,
                customerId: $customerId,
                status: OrderStatus::Processing,
                currency: $currency,
                subtotal: $subtotal,
                discountAmount: $discount,
                taxAmount: $taxResult->getTaxAmount(),
                taxRate: $taxResult->getTaxRate(),
                shippingAmount: $shippingResult->getShippingAmount(),
                shippingMethod: $shippingMethod,
                grandTotal: $grandTotal,
                billingAddress: $billingAddress,
                shippingAddress: $shippingAddress,
                paymentMethod: $paymentMethod,
                paymentTransactionId: $paymentResult->getTransactionId(),
                couponCode: $couponCode ? (string)$couponCode : null,
                items: $orderItems,
                customerNotes: isset($checkoutData['notes']) ? (string)$checkoutData['notes'] : null,
                createdAt: new DateTimeImmutable(),
                updatedAt: new DateTimeImmutable()
            );

            $savedOrder = $this->orderRepo->save($order);

            // 10. Clear cart if present
            if ($cart !== null && $cart->getId() !== null) {
                $delStmt = $this->pdo->prepare('DELETE FROM cart_items WHERE cart_id = :cid');
                $delStmt->execute([':cid' => $cart->getId()]);
            }

            $this->pdo->commit();
            return $savedOrder;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param mixed $raw
     */
    private function resolveAddress(mixed $raw): Address
    {
        if ($raw instanceof Address) {
            return $raw;
        }

        if (is_array($raw)) {
            return Address::fromArray($raw);
        }

        return new Address(
            firstName: 'Guest',
            lastName: 'Shopper',
            address1: '123 Main St',
            city: 'Springfield',
            state: 'IL',
            postalCode: '62701',
            country: 'US'
        );
    }

    private function findCoupon(string $code): ?Coupon
    {
        $stmt = $this->pdo->prepare('SELECT * FROM coupons WHERE UPPER(code) = :code AND is_active = 1');
        $stmt->execute([':code' => strtoupper(trim($code))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $usageLimit = isset($row['usage_limit']) && $row['usage_limit'] !== null ? (int)$row['usage_limit'] : null;
        $startsAt = isset($row['starts_at']) && $row['starts_at'] !== null
            ? new DateTimeImmutable((string)$row['starts_at'])
            : null;
        $expiresAt = isset($row['expires_at']) && $row['expires_at'] !== null
            ? new DateTimeImmutable((string)$row['expires_at'])
            : null;

        return new Coupon(
            id: (int)$row['id'],
            code: (string)$row['code'],
            discountType: (string)$row['discount_type'],
            discountValue: (int)$row['discount_value'],
            minSubtotal: (int)($row['min_subtotal'] ?? 0),
            usageLimit: $usageLimit,
            usageCount: (int)($row['usage_count'] ?? 0),
            startsAt: $startsAt,
            expiresAt: $expiresAt,
            isActive: (bool)($row['is_active'] ?? 1)
        );
    }
}
