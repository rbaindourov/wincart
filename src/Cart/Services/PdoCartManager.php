<?php

declare(strict_types=1);

namespace WinCart\Cart\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use WinCart\Cart\Exceptions\CartItemNotFoundException;
use WinCart\Cart\Exceptions\CartNotFoundException;
use WinCart\Cart\Exceptions\InvalidCouponException;
use WinCart\Cart\Models\Cart;
use WinCart\Cart\Models\CartItem;
use WinCart\Cart\Models\CartTotals;
use WinCart\Cart\Pricing\Coupon;
use WinCart\Cart\Pricing\CouponCalculator;
use WinCart\Cart\Pricing\ShippingCalculator;
use WinCart\Cart\Pricing\TaxEngine;
use WinCart\Catalog\Exceptions\InsufficientStockException;
use WinCart\Catalog\Exceptions\ProductNotFoundException;
use WinCart\Catalog\Repositories\PdoProductRepository;
use WinCart\Catalog\Repositories\ProductRepositoryInterface;
use WinCart\Common\Money;
use WinCart\Common\ProductType;

final class PdoCartManager implements CartManagerInterface
{
    private ProductRepositoryInterface $productRepo;
    private TaxEngine $taxEngine;
    private ShippingCalculator $shippingCalculator;

    public function __construct(
        private PDO $pdo,
        ?ProductRepositoryInterface $productRepo = null,
        ?TaxEngine $taxEngine = null,
        ?ShippingCalculator $shippingCalculator = null
    ) {
        $this->productRepo = $productRepo ?? new PdoProductRepository($pdo);
        $this->taxEngine = $taxEngine ?? new TaxEngine($pdo);
        $this->shippingCalculator = $shippingCalculator ?? new ShippingCalculator($pdo);
    }

    public function getOrCreateCart(?string $token = null, ?int $customerId = null): Cart
    {
        if ($customerId !== null) {
            $cart = $this->getCartByCustomerId($customerId);
            if ($cart !== null) {
                return $cart;
            }
        }

        if ($token !== null && trim($token) !== '') {
            $cart = $this->getCartByToken($token);
            if ($cart !== null) {
                return $cart;
            }
        }

        $newToken = $token !== null && trim($token) !== ''
            ? trim($token)
            : 'cart_' . bin2hex(random_bytes(12));

        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO carts (token, customer_id, created_at, updated_at) VALUES (:token, :cid, :created, :updated)'
        );
        $stmt->execute([
            ':token' => $newToken,
            ':cid' => $customerId,
            ':created' => $now,
            ':updated' => $now,
        ]);

        $id = (int)$this->pdo->lastInsertId();
        return new Cart(
            id: $id,
            token: $newToken,
            customerId: $customerId,
            items: [],
            currency: 'USD',
            createdAt: new DateTimeImmutable($now),
            updatedAt: new DateTimeImmutable($now)
        );
    }

    public function getCartByToken(string $token): ?Cart
    {
        $stmt = $this->pdo->prepare('SELECT * FROM carts WHERE token = :token');
        $stmt->execute([':token' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrateCart($row) : null;
    }

    public function getCartByCustomerId(int $customerId): ?Cart
    {
        $stmt = $this->pdo->prepare('SELECT * FROM carts WHERE customer_id = :cid ORDER BY id DESC LIMIT 1');
        $stmt->execute([':cid' => $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrateCart($row) : null;
    }

    public function addItem(Cart $cart, int $productId, int $quantity = 1, ?int $variationId = null): CartItem
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException("Cart item quantity must be greater than zero: {$quantity}");
        }

        $cartId = $cart->getId();
        if ($cartId === null) {
            throw new CartNotFoundException('Cart must be persisted before adding items');
        }

        // Validate stock
        if ($variationId !== null) {
            $variation = $this->productRepo->findVariationById($variationId);
            if ($variation === null) {
                throw new ProductNotFoundException("Variation #{$variationId} not found");
            }
            if ($variation->isManageStock()) {
                if ($variation->getStockQuantity() <= 0) {
                    throw new InsufficientStockException("Out of Stock: variation #{$variationId} has 0 stock");
                }
                if ($variation->getStockQuantity() < $quantity) {
                    throw new InsufficientStockException(
                        "Requested {$quantity} units exceeds available {$variation->getStockQuantity()} units"
                    );
                }
            }
            $product = $this->productRepo->findById($productId);
            $productName = $product ? $product->getName() : 'Product #' . $productId;
            $sku = $variation->getSku();
            $unitPrice = $variation->getEffectivePrice();
            $varAttrs = $variation->getAttributeMap();
            $type = ProductType::Variable;
        } else {
            $product = $this->productRepo->findById($productId);
            if ($product === null) {
                throw new ProductNotFoundException("Product #{$productId} not found");
            }
            if ($product->isManageStock() && !$product->isBackordersAllowed()) {
                if ($product->getStockQuantity() <= 0) {
                    throw new InsufficientStockException("Out of Stock: product #{$productId} has 0 stock");
                }
                if ($product->getStockQuantity() < $quantity) {
                    throw new InsufficientStockException(
                        "Requested {$quantity} units exceeds available {$product->getStockQuantity()} units"
                    );
                }
            }
            $productName = $product->getName();
            $sku = $product->getSku();
            $unitPrice = $product->getEffectivePrice();
            $varAttrs = [];
            $type = $product->getType();
        }

        $now = date('Y-m-d H:i:s');

        // Check if existing item in cart
        if ($variationId !== null) {
            $checkStmt = $this->pdo->prepare(
                'SELECT id, quantity FROM cart_items
                 WHERE cart_id = :cid AND product_id = :pid AND variation_id = :vid'
            );
            $checkStmt->execute([':cid' => $cartId, ':pid' => $productId, ':vid' => $variationId]);
        } else {
            $checkStmt = $this->pdo->prepare(
                'SELECT id, quantity FROM cart_items
                 WHERE cart_id = :cid AND product_id = :pid AND variation_id IS NULL'
            );
            $checkStmt->execute([':cid' => $cartId, ':pid' => $productId]);
        }
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $existingId = (int)$existing['id'];
            $newQty = (int)$existing['quantity'] + $quantity;
            $updateStmt = $this->pdo->prepare(
                'UPDATE cart_items SET quantity = :qty, updated_at = :updated WHERE id = :id'
            );
            $updateStmt->execute([':qty' => $newQty, ':updated' => $now, ':id' => $existingId]);

            return new CartItem(
                id: $existingId,
                cartId: $cartId,
                productId: $productId,
                productName: $productName,
                sku: $sku,
                unitPrice: $unitPrice,
                quantity: $newQty,
                variationId: $variationId,
                variationAttributes: $varAttrs,
                productType: $type
            );
        }

        $insertStmt = $this->pdo->prepare(
            'INSERT INTO cart_items (cart_id, product_id, variation_id, quantity, created_at, updated_at)
             VALUES (:cid, :pid, :vid, :qty, :created, :updated)'
        );
        $insertStmt->execute([
            ':cid' => $cartId,
            ':pid' => $productId,
            ':vid' => $variationId,
            ':qty' => $quantity,
            ':created' => $now,
            ':updated' => $now,
        ]);

        $itemId = (int)$this->pdo->lastInsertId();

        return new CartItem(
            id: $itemId,
            cartId: $cartId,
            productId: $productId,
            productName: $productName,
            sku: $sku,
            unitPrice: $unitPrice,
            quantity: $quantity,
            variationId: $variationId,
            variationAttributes: $varAttrs,
            productType: $type
        );
    }

    public function updateItemQuantity(Cart $cart, int $itemId, int $quantity): CartItem
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException("Cart item quantity must be greater than zero: {$quantity}");
        }

        $stmt = $this->pdo->prepare('SELECT * FROM cart_items WHERE id = :id AND cart_id = :cid');
        $stmt->execute([':id' => $itemId, ':cid' => $cart->getId()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new CartItemNotFoundException("Cart item #{$itemId} not found");
        }

        $pid = (int)$row['product_id'];
        $vid = isset($row['variation_id']) && $row['variation_id'] !== null ? (int)$row['variation_id'] : null;

        // Check stock
        if ($vid !== null) {
            $variation = $this->productRepo->findVariationById($vid);
            if ($variation && $variation->isManageStock() && $variation->getStockQuantity() < $quantity) {
                throw new InsufficientStockException("Quantity {$quantity} exceeds stock");
            }
        } else {
            $product = $this->productRepo->findById($pid);
            if ($product && $product->isManageStock() && $product->getStockQuantity() < $quantity) {
                throw new InsufficientStockException("Quantity {$quantity} exceeds stock");
            }
        }

        $now = date('Y-m-d H:i:s');
        $upStmt = $this->pdo->prepare(
            'UPDATE cart_items SET quantity = :qty, updated_at = :updated WHERE id = :id'
        );
        $upStmt->execute([':qty' => $quantity, ':updated' => $now, ':id' => $itemId]);

        $fetchStmt = $this->pdo->prepare('SELECT * FROM cart_items WHERE id = :id');
        $fetchStmt->execute([':id' => $itemId]);
        $updatedRow = $fetchStmt->fetch(PDO::FETCH_ASSOC);

        return $this->hydrateCartItem($updatedRow);
    }

    public function removeItem(Cart $cart, int $itemId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM cart_items WHERE id = :id AND cart_id = :cid');
        $stmt->execute([':id' => $itemId, ':cid' => $cart->getId()]);
        return $stmt->rowCount() > 0;
    }

    public function applyCoupon(Cart $cart, string $couponCode): Cart
    {
        $code = strtoupper(trim($couponCode));
        $coupon = $this->findCouponByCode($code);

        if ($coupon === null || !$coupon->isValid($cart->getSubtotal())) {
            throw new InvalidCouponException("Invalid or expired coupon code: {$code}");
        }

        $stmt = $this->pdo->prepare('UPDATE carts SET coupon_code = :code, updated_at = :now WHERE id = :id');
        $stmt->execute([':code' => $code, ':now' => date('Y-m-d H:i:s'), ':id' => $cart->getId()]);

        return $this->getCartByToken($cart->getToken()) ?? $cart;
    }

    public function removeCoupon(Cart $cart): Cart
    {
        $stmt = $this->pdo->prepare('UPDATE carts SET coupon_code = NULL, updated_at = :now WHERE id = :id');
        $stmt->execute([':now' => date('Y-m-d H:i:s'), ':id' => $cart->getId()]);

        return $this->getCartByToken($cart->getToken()) ?? $cart;
    }

    public function clearCart(Cart $cart): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM cart_items WHERE cart_id = :cid');
        $stmt->execute([':cid' => $cart->getId()]);
        return true;
    }

    public function calculateTotals(Cart $cart, ?string $state = null, ?string $country = 'US'): CartTotals
    {
        $subtotal = $cart->getSubtotal();
        $discount = Money::zero($subtotal->getCurrency());
        $couponCode = $cart->getCouponCode();

        if ($couponCode !== null) {
            $coupon = $this->findCouponByCode($couponCode);
            if ($coupon !== null && $coupon->isValid($subtotal)) {
                $discount = CouponCalculator::calculateDiscount($coupon, $subtotal);
            }
        }

        $discountedSubtotal = $subtotal->subtract($discount);
        $taxState = $state ?? ($cart->getShippingAddress()?->state ?? '');
        $taxResult = $this->taxEngine->calculateTax($discountedSubtotal, $taxState, $country ?? 'US');

        $shippingMethod = $cart->getShippingMethod() ?? 'standard_ground';
        $shippingResult = $this->shippingCalculator->calculateShipping(
            $cart,
            $discountedSubtotal,
            $shippingMethod,
            $taxState
        );

        $grandTotal = $discountedSubtotal->add($taxResult->getTaxAmount())->add($shippingResult->getShippingAmount());

        return new CartTotals(
            subtotal: $subtotal,
            discountAmount: $discount,
            discountedSubtotal: $discountedSubtotal,
            taxAmount: $taxResult->getTaxAmount(),
            shippingAmount: $shippingResult->getShippingAmount(),
            grandTotal: $grandTotal,
            couponCode: $couponCode,
            taxZone: $taxResult->getTaxZone()
        );
    }

    public function mergeGuestCartIntoCustomerCart(string $guestToken, int $customerId): Cart
    {
        $guestCart = $this->getCartByToken($guestToken);
        $customerCart = $this->getCartByCustomerId($customerId);

        if ($guestCart === null) {
            return $customerCart ?? $this->getOrCreateCart(null, $customerId);
        }

        if ($customerCart === null) {
            // Assign guest cart directly to customer
            $stmt = $this->pdo->prepare('UPDATE carts SET customer_id = :cid WHERE id = :id');
            $stmt->execute([':cid' => $customerId, ':id' => $guestCart->getId()]);
            return $this->getCartByToken($guestCart->getToken()) ?? $guestCart;
        }

        // Both carts exist: consolidate guest items into customer cart
        $custCartId = (int)$customerCart->getId();
        $guestItems = $guestCart->getItems();

        foreach ($guestItems as $gItem) {
            $pid = $gItem->getProductId();
            $vid = $gItem->getVariationId();
            $qty = $gItem->getQuantity();

            $existingCustItem = $customerCart->findItemByProduct($pid, $vid);
            if ($existingCustItem !== null) {
                // Update quantity
                $newQty = $existingCustItem->getQuantity() + $qty;
                $upStmt = $this->pdo->prepare('UPDATE cart_items SET quantity = :qty WHERE id = :id');
                $upStmt->execute([':qty' => $newQty, ':id' => $existingCustItem->getId()]);
            } else {
                // Move item to customer cart
                $moveStmt = $this->pdo->prepare('UPDATE cart_items SET cart_id = :cid WHERE id = :id');
                $moveStmt->execute([':cid' => $custCartId, ':id' => $gItem->getId()]);
            }
        }

        // Transfer coupon if customer cart had none
        if ($customerCart->getCouponCode() === null && $guestCart->getCouponCode() !== null) {
            $cStmt = $this->pdo->prepare('UPDATE carts SET coupon_code = :code WHERE id = :id');
            $cStmt->execute([':code' => $guestCart->getCouponCode(), ':id' => $custCartId]);
        }

        // Delete guest cart record
        $delStmt = $this->pdo->prepare('DELETE FROM carts WHERE id = :id');
        $delStmt->execute([':id' => $guestCart->getId()]);

        return $this->getCartByCustomerId($customerId) ?? $customerCart;
    }

    private function findCouponByCode(string $code): ?Coupon
    {
        $stmt = $this->pdo->prepare('SELECT * FROM coupons WHERE UPPER(code) = :code AND is_active = 1');
        $stmt->execute([':code' => strtoupper(trim($code))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $usageLimit = isset($row['usage_limit']) && $row['usage_limit'] !== null
            ? (int)$row['usage_limit']
            : null;
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

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateCart(array $row): Cart
    {
        $id = (int)$row['id'];
        $itemsStmt = $this->pdo->prepare('SELECT * FROM cart_items WHERE cart_id = :id ORDER BY id ASC');
        $itemsStmt->execute([':id' => $id]);
        $itemRows = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        $items = array_map(fn(array $r) => $this->hydrateCartItem($r), $itemRows);

        return new Cart(
            id: $id,
            token: (string)$row['token'],
            customerId: isset($row['customer_id']) && $row['customer_id'] !== null ? (int)$row['customer_id'] : null,
            items: $items,
            couponCode: isset($row['coupon_code']) ? (string)$row['coupon_code'] : null,
            shippingAddress: null,
            billingAddress: null,
            shippingMethod: null,
            currency: 'USD',
            createdAt: isset($row['created_at']) ? new DateTimeImmutable((string)$row['created_at']) : null,
            updatedAt: isset($row['updated_at']) ? new DateTimeImmutable((string)$row['updated_at']) : null
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateCartItem(array $row): CartItem
    {
        $pid = (int)$row['product_id'];
        $vid = isset($row['variation_id']) && $row['variation_id'] !== null ? (int)$row['variation_id'] : null;

        if ($vid !== null) {
            $variation = $this->productRepo->findVariationById($vid);
            $product = $this->productRepo->findById($pid);
            $name = $product ? $product->getName() : 'Product #' . $pid;
            $sku = $variation ? $variation->getSku() : 'SKU-' . $pid;
            $unitPrice = $variation ? $variation->getEffectivePrice() : new Money(0, 'USD');
            $attrs = $variation ? $variation->getAttributeMap() : [];
            $type = ProductType::Variable;
        } else {
            $product = $this->productRepo->findById($pid);
            $name = $product ? $product->getName() : 'Product #' . $pid;
            $sku = $product ? $product->getSku() : 'SKU-' . $pid;
            $unitPrice = $product ? $product->getEffectivePrice() : new Money(0, 'USD');
            $attrs = [];
            $type = $product ? $product->getType() : ProductType::Simple;
        }

        return new CartItem(
            id: (int)$row['id'],
            cartId: (int)$row['cart_id'],
            productId: $pid,
            productName: $name,
            sku: $sku,
            unitPrice: $unitPrice,
            quantity: (int)$row['quantity'],
            variationId: $vid,
            variationAttributes: $attrs,
            productType: $type
        );
    }
}
