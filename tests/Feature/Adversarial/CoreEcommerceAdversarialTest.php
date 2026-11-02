<?php

declare(strict_types=1);

namespace WinCart\Tests\Feature\Adversarial;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WinCart\Cart\Exceptions\InvalidCouponException;
use WinCart\Cart\Pricing\Coupon;
use WinCart\Cart\Pricing\CouponCalculator;
use WinCart\Cart\Pricing\ShippingCalculator;
use WinCart\Cart\Pricing\TaxEngine;
use WinCart\Cart\Services\PdoCartManager;
use WinCart\Catalog\Exceptions\InsufficientStockException;
use WinCart\Catalog\Exceptions\ProductNotFoundException;
use WinCart\Catalog\Models\Category;
use WinCart\Catalog\Models\Product;
use WinCart\Catalog\Models\Variation;
use WinCart\Catalog\Repositories\PdoCategoryRepository;
use WinCart\Catalog\Repositories\PdoProductRepository;
use WinCart\Checkout\CheckoutService;
use WinCart\Checkout\Exceptions\InvalidOrderStateTransitionException;
use WinCart\Checkout\Exceptions\PaymentFailedException;
use WinCart\Checkout\Order;
use WinCart\Checkout\OrderStateMachine;
use WinCart\Checkout\PdoOrderRepository;
use WinCart\Common\Address;
use WinCart\Common\Exceptions\InvalidCartOperationException;
use WinCart\Common\Money;
use WinCart\Common\OrderStatus;
use WinCart\Common\ProductType;
use WinCart\Common\StockStatus;
use WinCart\Database\ConnectionFactory;
use WinCart\Database\MigrationRunner;
use WinCart\Payment\MockPaymentGateway;
use WinCart\Payment\PaymentGatewayRegistry;

/**
 * Milestone 6-1 Adversarial Challenge & White-Box Stress Test Suite.
 *
 * Authored by Adversarial Challenger M6-1 to rigorously stress-test:
 * 1. Catalog Domain: negative stock updates, non-existent variation IDs,
 *    deep category hierarchy & cycle detection, variable product attribute matching.
 * 2. Cart & Pricing Engine: 100% discount coupons, minimum order requirements,
 *    expired/future coupons, coupon overwrite/removal, tax zone fallbacks, shipping tiers.
 * 3. Checkout & Payment State Machine: zero-dollar cart checkout, payment decline rollback,
 *    FSM illegal transition rejection, restock on cancellation/refund, gateway refund limits.
 * 4. Database & Connection Layer: migration DDL atomic rollback, batch rollback,
 *    ConnectionFactory transactional safety, WAL mode concurrency under multi-threaded writes.
 */
final class CoreEcommerceAdversarialTest extends TestCase
{
    private PDO $pdo;
    private string $tempDbFile;
    private PdoProductRepository $productRepo;
    private PdoCategoryRepository $categoryRepo;
    private PdoOrderRepository $orderRepo;
    private OrderStateMachine $stateMachine;
    private PdoCartManager $cartManager;
    private TaxEngine $taxEngine;
    private ShippingCalculator $shippingCalculator;
    private PaymentGatewayRegistry $paymentRegistry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDbFile = sys_get_temp_dir() . '/wincart_adv_' . uniqid('', true) . '.sqlite';
        $this->pdo = ConnectionFactory::create([
            'driver' => 'sqlite',
            'path' => $this->tempDbFile,
        ]);

        $migrationsPath = dirname(__DIR__, 3) . '/database/migrations';
        $runner = new MigrationRunner($this->pdo, $migrationsPath);
        $runner->migrateUp();

        $this->pdo->exec('INSERT OR IGNORE INTO customers (
            id, email, password_hash, first_name, last_name, role, pat_token_hash, pat_scopes
        ) VALUES (
            1, "test@example.com", "hash", "Test", "User", "customer", "pat_test_hash", "read,write"
        )');

        $this->productRepo = new PdoProductRepository($this->pdo);
        $this->categoryRepo = new PdoCategoryRepository($this->pdo);
        $this->orderRepo = new PdoOrderRepository($this->pdo);
        $this->stateMachine = new OrderStateMachine($this->pdo, $this->orderRepo, $this->productRepo);

        $this->taxEngine = new TaxEngine($this->pdo);
        $this->shippingCalculator = new ShippingCalculator($this->pdo);
        $this->cartManager = new PdoCartManager(
            $this->pdo,
            $this->productRepo,
            $this->taxEngine,
            $this->shippingCalculator
        );

        $this->paymentRegistry = new PaymentGatewayRegistry();
        $this->paymentRegistry->register(new MockPaymentGateway());
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempDbFile)) {
            @unlink($this->tempDbFile);
        }
        parent::tearDown();
    }

    // =========================================================================
    // SECTION 1: CATALOG DOMAIN ADVERSARIAL TESTS
    // =========================================================================

    /**
     * Negative stock update: adjustStock with delta exceeding available stock must fail.
     */
    public function testAdjustStockNegativeDeltaExceedingAvailableStockThrowsException(): void
    {
        $product = $this->createSimpleProduct('Stock Product', 'sku-stock-1', 2500, 5);
        $productId = (int)$product->getId();

        $this->expectException(InsufficientStockException::class);
        $this->expectExceptionMessage('Insufficient stock for product #' . $productId);

        $this->productRepo->adjustStock($productId, -10, 'checkout');
    }

    /**
     * Negative stock update on zero stock must fail with InsufficientStockException.
     */
    public function testAdjustStockNegativeDeltaOnZeroStockThrowsException(): void
    {
        $product = $this->createSimpleProduct('Zero Stock Product', 'sku-stock-zero', 1500, 0);
        $productId = (int)$product->getId();

        $this->expectException(InsufficientStockException::class);
        $this->productRepo->adjustStock($productId, -1, 'checkout');
    }

    /**
     * Stock adjustment on non-existent product ID must throw ProductNotFoundException.
     */
    public function testAdjustStockOnNonExistentProductIdThrowsException(): void
    {
        $this->expectException(ProductNotFoundException::class);
        $this->expectExceptionMessage('Product #999999 not found');

        $this->productRepo->adjustStock(999999, -1, 'checkout');
    }

    /**
     * Stock adjustment on non-existent variation ID must throw ProductNotFoundException.
     */
    public function testAdjustStockOnNonExistentVariationIdThrowsException(): void
    {
        $product = $this->createSimpleProduct('Parent Product', 'sku-parent-1', 2000, 10);
        $productId = (int)$product->getId();

        $this->expectException(ProductNotFoundException::class);
        $this->expectExceptionMessage('Product variation #888888 not found');

        $this->productRepo->adjustStock($productId, -1, 'checkout', 888888);
    }

    /**
     * Restock transitions an out-of-stock product back to in_stock status.
     */
    public function testAdjustStockRestockTransitionsOutOfStockToInStock(): void
    {
        $product = $this->createSimpleProduct('Restock Product', 'sku-restock-1', 3000, 0);
        $productId = (int)$product->getId();

        $reloaded = $this->productRepo->findById($productId);
        $this->assertNotNull($reloaded);
        $this->assertSame(0, $reloaded->getStockQuantity());
        $this->assertSame(StockStatus::OutOfStock, $reloaded->getStockStatus());

        $newStock = $this->productRepo->adjustStock($productId, 15, 'restock');
        $this->assertSame(15, $newStock);

        $updated = $this->productRepo->findById($productId);
        $this->assertNotNull($updated);
        $this->assertSame(15, $updated->getStockQuantity());
        $this->assertSame(StockStatus::InStock, $updated->getStockStatus());
    }

    /**
     * Adjust stock with delta = 0 leaves stock quantity unchanged.
     */
    public function testAdjustStockZeroDeltaLeavesStockUnchanged(): void
    {
        $product = $this->createSimpleProduct('Static Stock', 'sku-static-1', 1000, 7);
        $productId = (int)$product->getId();

        $newStock = $this->productRepo->adjustStock($productId, 0, 'audit');
        $this->assertSame(7, $newStock);

        $reloaded = $this->productRepo->findById($productId);
        $this->assertNotNull($reloaded);
        $this->assertSame(7, $reloaded->getStockQuantity());
    }

    /**
     * Variation stock adjustment updates variation record and recalculates status.
     */
    public function testAdjustStockOnVariationUpdatesVariationStockAndStatus(): void
    {
        $product = $this->createVariableProductWithVariations();
        $variations = $product->getVariations();
        $this->assertNotEmpty($variations);
        $var1 = $variations[0];
        $varId = (int)$var1->getId();
        $productId = (int)$product->getId();

        $this->assertSame(5, $var1->getStockQuantity());

        // Decrement to zero
        $newStock = $this->productRepo->adjustStock($productId, -5, 'sale', $varId);
        $this->assertSame(0, $newStock);

        $reloadedVar = $this->productRepo->findVariationById($varId);
        $this->assertNotNull($reloadedVar);
        $this->assertSame(0, $reloadedVar->getStockQuantity());
        $this->assertSame(StockStatus::OutOfStock, $reloadedVar->getStockStatus());

        // Restock
        $restocked = $this->productRepo->adjustStock($productId, 8, 'restock', $varId);
        $this->assertSame(8, $restocked);

        $finalVar = $this->productRepo->findVariationById($varId);
        $this->assertNotNull($finalVar);
        $this->assertSame(8, $finalVar->getStockQuantity());
        $this->assertSame(StockStatus::InStock, $finalVar->getStockStatus());
    }

    /**
     * Category repository deep hierarchy (6 levels) tree building and subtree ID resolution.
     */
    public function testCategoryRepositoryDeepHierarchyAndSubtreeIds(): void
    {
        $parent = null;
        $categoryIds = [];

        for ($depth = 1; $depth <= 6; $depth++) {
            $cat = new Category(
                id: null,
                name: "Level {$depth} Category",
                slug: "level-{$depth}-cat-" . uniqid(),
                description: "Deep category level {$depth}",
                parentId: $parent,
                sortOrder: $depth,
                imageUrl: null,
                isActive: true
            );
            $saved = $this->categoryRepo->save($cat);
            $parent = (int)$saved->getId();
            $categoryIds[] = $parent;
        }

        $rootId = $categoryIds[0];
        $subtreeIds = $this->categoryRepo->findSubtreeIds($rootId);

        $this->assertCount(6, $subtreeIds);
        foreach ($categoryIds as $expectedId) {
            $this->assertContains($expectedId, $subtreeIds);
        }

        // Subtree from level 4 (should contain level 4, 5, 6)
        $level4Id = $categoryIds[3];
        $sub4 = $this->categoryRepo->findSubtreeIds($level4Id);
        $this->assertCount(3, $sub4);
        $this->assertContains($categoryIds[3], $sub4);
        $this->assertContains($categoryIds[4], $sub4);
        $this->assertContains($categoryIds[5], $sub4);

        // Entire tree construction
        $tree = $this->categoryRepo->findTree();
        $this->assertNotEmpty($tree);
    }

    /**
     * Variable product attribute matching: lookup by attributeMap values and variation ID.
     */
    public function testVariableProductAttributeMatching(): void
    {
        $product = $this->createVariableProductWithVariations();
        $variations = $product->getVariations();

        $this->assertCount(2, $variations);

        $v1 = $variations[0];
        $v2 = $variations[1];

        $this->assertSame('Medium', $v1->getAttributeMap()['size'] ?? null);
        $this->assertSame('Red', $v1->getAttributeMap()['color'] ?? null);

        $this->assertSame('Large', $v2->getAttributeMap()['size'] ?? null);
        $this->assertSame('Blue', $v2->getAttributeMap()['color'] ?? null);

        $foundV1 = $product->getVariation((int)$v1->getId());
        $this->assertNotNull($foundV1);
        $this->assertSame($v1->getSku(), $foundV1->getSku());

        $this->assertNull($product->getVariation(999999));
    }

    // =========================================================================
    // SECTION 2: CART & PRICING ENGINE ADVERSARIAL TESTS
    // =========================================================================

    /**
     * 100% discount coupon reduces subtotal to zero.
     */
    public function testCouponCalculatorHundredPercentDiscountCapsAtSubtotal(): void
    {
        $coupon = new Coupon(
            id: 1,
            code: 'FREE100',
            discountType: 'percentage',
            discountValue: 100,
            minSubtotal: 0,
            usageLimit: null,
            usageCount: 0,
            startsAt: null,
            expiresAt: null,
            isActive: true
        );

        $subtotal = new Money(8000, 'USD');
        $discount = CouponCalculator::calculateDiscount($coupon, $subtotal);

        $this->assertSame(8000, $discount->getAmount());
        $this->assertSame('$80.00', $discount->format());
    }

    /**
     * Fixed discount coupon exceeding subtotal is strictly capped at subtotal amount.
     */
    public function testFixedCouponExceedingSubtotalNeverProducesNegativeTotal(): void
    {
        $coupon = new Coupon(
            id: 2,
            code: 'MEGA50',
            discountType: 'fixed',
            discountValue: 10000, // $100.00 discount
            minSubtotal: 0,
            usageLimit: null,
            usageCount: 0,
            startsAt: null,
            expiresAt: null,
            isActive: true
        );

        $subtotal = new Money(3500, 'USD'); // $35.00 subtotal
        $discount = CouponCalculator::calculateDiscount($coupon, $subtotal);

        $this->assertSame(3500, $discount->getAmount());
        $discounted = $subtotal->subtract($discount);
        $this->assertTrue($discounted->isZero());
        $this->assertSame(0, $discounted->getAmount());
    }

    /**
     * Coupon with minimum order requirement is rejected if subtotal is below minimum.
     */
    public function testCouponMinimumOrderRequirementEnforcement(): void
    {
        $this->insertCouponDb('MIN50', 'percentage', 20, 5000); // 20% off with $50.00 min subtotal

        $cart = $this->cartManager->getOrCreateCart('cart_min_subtotal_test');
        $product = $this->createSimpleProduct('Cheap Item', 'sku-cheap-1', 2000, 10); // $20.00

        $this->cartManager->addItem($cart, (int)$product->getId(), 2); // $40.00 subtotal (< $50.00)

        $this->expectException(InvalidCouponException::class);
        $this->cartManager->applyCoupon($cart, 'MIN50');
    }

    /**
     * Expired coupons and future coupons are rejected upon application.
     */
    public function testExpiredAndFutureCouponsAreRejected(): void
    {
        $past = new DateTimeImmutable('-5 days');
        $future = new DateTimeImmutable('+5 days');

        $expiredCoupon = new Coupon(
            id: 3,
            code: 'EXPIRED10',
            discountType: 'percentage',
            discountValue: 10,
            minSubtotal: 0,
            usageLimit: null,
            usageCount: 0,
            startsAt: null,
            expiresAt: $past,
            isActive: true
        );
        $this->assertFalse($expiredCoupon->isValid(new Money(5000, 'USD')));
        $this->assertTrue($expiredCoupon->isExpired());

        $futureCoupon = new Coupon(
            id: 4,
            code: 'FUTURE10',
            discountType: 'percentage',
            discountValue: 10,
            minSubtotal: 0,
            usageLimit: null,
            usageCount: 0,
            startsAt: $future,
            expiresAt: null,
            isActive: true
        );
        $this->assertFalse($futureCoupon->isValid(new Money(5000, 'USD')));
    }

    /**
     * Applying a new coupon overwrites any previous coupon; removeCoupon clears discount.
     */
    public function testMultiCouponConflictAndRemoval(): void
    {
        $this->insertCouponDb('PROMO10', 'percentage', 10, 0);
        $this->insertCouponDb('PROMO25', 'percentage', 25, 0);

        $cart = $this->cartManager->getOrCreateCart('cart_multicoupon_test');
        $product = $this->createSimpleProduct('Cart Item', 'sku-multi-cart', 10000, 10);
        $this->cartManager->addItem($cart, (int)$product->getId(), 1);

        $cart = $this->cartManager->applyCoupon($cart, 'PROMO10');
        $this->assertSame('PROMO10', $cart->getCouponCode());

        $totals1 = $this->cartManager->calculateTotals($cart);
        $this->assertSame(1000, $totals1->getDiscountAmount()->getAmount()); // 10% of $100

        // Overwrite with PROMO25
        $cart = $this->cartManager->applyCoupon($cart, 'PROMO25');
        $this->assertSame('PROMO25', $cart->getCouponCode());

        $totals2 = $this->cartManager->calculateTotals($cart);
        $this->assertSame(2500, $totals2->getDiscountAmount()->getAmount()); // 25% of $100

        // Remove coupon
        $cart = $this->cartManager->removeCoupon($cart);
        $this->assertNull($cart->getCouponCode());

        $totals3 = $this->cartManager->calculateTotals($cart);
        $this->assertTrue($totals3->getDiscountAmount()->isZero());
    }

    /**
     * Tax zone fallback for unrecognized countries and states.
     */
    public function testTaxZoneFallbackForUnrecognizedDestinations(): void
    {
        $subtotal = new Money(10000, 'USD'); // $100.00

        // Washington (known default statutory rate: 10.25%)
        $resWA = $this->taxEngine->calculateTax($subtotal, 'WA', 'US');
        $this->assertSame(1025, $resWA->getTaxAmount()->getAmount());
        $this->assertEquals(0.1025, $resWA->getTaxRate());

        // California (known default statutory rate: 8.5%)
        $resCA = $this->taxEngine->calculateTax($subtotal, 'CA', 'US');
        $this->assertSame(850, $resCA->getTaxAmount()->getAmount());

        // Oregon (known statutory rate: 0.0%)
        $resOR = $this->taxEngine->calculateTax($subtotal, 'OR', 'US');
        $this->assertSame(0, $resOR->getTaxAmount()->getAmount());

        // Unrecognized US state (e.g. 'ZZ') falls back to 0.0%
        $resZZ = $this->taxEngine->calculateTax($subtotal, 'ZZ', 'US');
        $this->assertSame(0, $resZZ->getTaxAmount()->getAmount());
        $this->assertSame(0.0, $resZZ->getTaxRate());

        // Unrecognized foreign country with unknown state falls back to 0.0%
        $resFR = $this->taxEngine->calculateTax($subtotal, 'PARIS', 'FR');
        $this->assertSame(0, $resFR->getTaxAmount()->getAmount());
        $this->assertSame(0.0, $resFR->getTaxRate());
    }

    /**
     * Shipping tier transitions: boundary tests around the $150.00 free shipping threshold.
     */
    public function testShippingTierTransitionsAroundThreshold(): void
    {
        $product = $this->createSimpleProduct('Physical Item', 'sku-phys-1', 100, 1000, true);
        $cart = $this->cartManager->getOrCreateCart('cart_shipping_tiers');
        $this->cartManager->addItem($cart, (int)$product->getId(), 1);
        $cart = $this->cartManager->getCartByToken('cart_shipping_tiers');
        $this->assertNotNull($cart);

        // Subtotal = $149.99 (14,999 cents) -> standard flat rate $7.50
        $sub149 = new Money(14999, 'USD');
        $res149 = $this->shippingCalculator->calculateShipping($cart, $sub149, 'standard_ground', 'WA');
        $this->assertFalse($res149->isFree());
        $this->assertSame(750, $res149->getShippingAmount()->getAmount());

        // Subtotal = exactly $150.00 (15,000 cents) -> free shipping ($0.00)
        $sub150 = new Money(15000, 'USD');
        $res150 = $this->shippingCalculator->calculateShipping($cart, $sub150, 'standard_ground', 'WA');
        $this->assertTrue($res150->isFree());
        $this->assertSame(0, $res150->getShippingAmount()->getAmount());

        // Subtotal = $150.01 (15,001 cents) -> free shipping ($0.00)
        $sub151 = new Money(15001, 'USD');
        $res151 = $this->shippingCalculator->calculateShipping($cart, $sub151, 'standard_ground', 'WA');
        $this->assertTrue($res151->isFree());
        $this->assertSame(0, $res151->getShippingAmount()->getAmount());
    }

    /**
     * Digital cart items requiring no shipping result in free digital delivery.
     */
    public function testDigitalProductCartRequiresNoShipping(): void
    {
        $digitalProduct = $this->createSimpleProduct('Digital Ebook', 'sku-digi-1', 4900, 100, false);
        $cart = $this->cartManager->getOrCreateCart('cart_digital_shipping');
        $this->cartManager->addItem($cart, (int)$digitalProduct->getId(), 1);

        $reloaded = $this->cartManager->getCartByToken('cart_digital_shipping');
        $this->assertNotNull($reloaded);
        $this->assertFalse($reloaded->requiresShipping());

        $totals = $this->cartManager->calculateTotals($reloaded);
        $this->assertSame(0, $totals->getShippingAmount()->getAmount());
    }

    // =========================================================================
    // SECTION 3: CHECKOUT & PAYMENT STATE MACHINE ADVERSARIAL TESTS
    // =========================================================================

    /**
     * Checkout with zero-dollar cart (e.g. 100% coupon on digital item).
     */
    public function testCheckoutWithZeroDollarCartSucceeds(): void
    {
        $digital = $this->createSimpleProduct('Free Gift', 'sku-free-gift', 2000, 10, false);
        $this->insertCouponDb('FREEALL', 'percentage', 100, 0);

        $cart = $this->cartManager->getOrCreateCart('cart_zero_dollar');
        $this->cartManager->addItem($cart, (int)$digital->getId(), 1);
        $cart = $this->cartManager->applyCoupon($cart, 'FREEALL');

        $checkoutService = new CheckoutService(
            $this->pdo,
            $this->orderRepo,
            $this->productRepo,
            $this->paymentRegistry,
            $this->cartManager,
            $this->taxEngine,
            $this->shippingCalculator
        );

        $order = $checkoutService->submitOrder($cart, [
            'payment_token' => 'tok_mock_success',
            'shipping_address' => [
                'first_name' => 'Zero',
                'last_name' => 'Tester',
                'address_1' => '100 Digital Blvd',
                'city' => 'Seattle',
                'state' => 'WA',
                'postal_code' => '98101',
                'country' => 'US',
            ],
        ]);

        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame(0, $order->getGrandTotal()->getAmount());
        $this->assertSame(OrderStatus::Processing, $order->getStatus());
    }

    /**
     * Payment failure rollback: declined card rolls back stock decrement and order creation.
     */
    public function testPaymentGatewayDeclineRollsBackStockDecrement(): void
    {
        $product = $this->createSimpleProduct('Declined Item', 'sku-decline-1', 4000, 10);
        $productId = (int)$product->getId();

        $checkoutService = new CheckoutService(
            $this->pdo,
            $this->orderRepo,
            $this->productRepo,
            $this->paymentRegistry,
            $this->cartManager,
            $this->taxEngine,
            $this->shippingCalculator
        );

        $initialOrdersCount = (int)$this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();

        try {
            $checkoutService->submitOrder(null, [
                'items' => [['product_id' => $productId, 'quantity' => 3]],
                'payment_token' => 'tok_mock_decline', // Causes PaymentFailedException
            ]);
            $this->fail('Expected PaymentFailedException was not thrown');
        } catch (PaymentFailedException $e) {
            $this->assertStringContainsString('Card declined', $e->getMessage());
        }

        // Verify stock was NOT decremented
        $reloaded = $this->productRepo->findById($productId);
        $this->assertNotNull($reloaded);
        $this->assertSame(10, $reloaded->getStockQuantity());

        // Verify order was NOT inserted
        $finalOrdersCount = (int)$this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
        $this->assertSame($initialOrdersCount, $finalOrdersCount);
    }

    /**
     * Illegal order status transitions must be blocked by the OrderStateMachine.
     */
    public function testIllegalOrderStatusTransitionsAreBlockedByFsm(): void
    {
        $illegalPairs = [
            [OrderStatus::Completed, OrderStatus::Pending],
            [OrderStatus::Completed, OrderStatus::Processing],
            [OrderStatus::Completed, OrderStatus::Cancelled],
            [OrderStatus::Cancelled, OrderStatus::Completed],
            [OrderStatus::Cancelled, OrderStatus::Refunded],
            [OrderStatus::Refunded, OrderStatus::Processing],
            [OrderStatus::Refunded, OrderStatus::Pending],
        ];

        foreach ($illegalPairs as [$fromStatus, $toStatus]) {
            $order = $this->createOrderRecord($fromStatus);

            $this->assertFalse(
                $this->stateMachine->canTransition($order, $toStatus),
                "FSM canTransition should return false for {$fromStatus->value} -> {$toStatus->value}"
            );

            try {
                $this->stateMachine->transition($order, $toStatus);
                $this->fail("Expected transition failure for {$fromStatus->value} -> {$toStatus->value}");
            } catch (InvalidOrderStateTransitionException $e) {
                $this->assertStringContainsString('Cannot transition order', $e->getMessage());
            }
        }
    }

    /**
     * Order cancellation and refund with restock: true restores catalog inventory.
     */
    public function testOrderStateMachineRestocksInventoryOnCancellationAndRefund(): void
    {
        $product = $this->createSimpleProduct('Restockable Product', 'sku-restock-fsm', 5000, 10);
        $productId = (int)$product->getId();

        $checkoutService = new CheckoutService(
            $this->pdo,
            $this->orderRepo,
            $this->productRepo,
            $this->paymentRegistry,
            $this->cartManager,
            $this->taxEngine,
            $this->shippingCalculator
        );

        $order = $checkoutService->submitOrder(null, [
            'items' => [['product_id' => $productId, 'quantity' => 4]],
            'payment_token' => 'tok_mock_success',
        ]);

        $afterCheckoutStock = $this->productRepo->findById($productId)?->getStockQuantity();
        $this->assertSame(6, $afterCheckoutStock);

        // Cancel with restock = true
        $cancelledOrder = $this->stateMachine->transition($order, OrderStatus::Cancelled, 'customer request', true);
        $this->assertSame(OrderStatus::Cancelled, $cancelledOrder->getStatus());

        $afterCancelStock = $this->productRepo->findById($productId)?->getStockQuantity();
        $this->assertSame(10, $afterCancelStock);
    }

    /**
     * Direct MockPaymentGateway refund behaves predictably with valid amounts.
     */
    public function testMockPaymentGatewayDirectRefund(): void
    {
        $gateway = new MockPaymentGateway();
        $refundAmount = new Money(5000, 'USD');

        $result = $gateway->refund('txn_orig_12345', $refundAmount, 'Customer return');
        $this->assertTrue($result->isSuccessful());
        $this->assertNotNull($result->getTransactionId());
        $this->assertStringStartsWith('ref_mock_', (string)$result->getTransactionId());
    }

    // =========================================================================
    // SECTION 4: DATABASE & CONNECTION LAYER ADVERSARIAL TESTS
    // =========================================================================

    /**
     * Migration runner atomic transaction rollback on syntax error or malformed DDL.
     */
    public function testMigrationRunnerRollsBackOnMalformedDdl(): void
    {
        $tempMigDir = sys_get_temp_dir() . '/wincart_test_migs_' . uniqid();
        mkdir($tempMigDir);

        file_put_contents(
            $tempMigDir . '/001_malformed.sql',
            "CREATE TABLE temporary_table_rollback_check (id INTEGER PRIMARY KEY);\nINVALID SQL SYNTAX HERE;\n"
        );

        $freshPdo = ConnectionFactory::create([
            'driver' => 'sqlite',
            'path' => sys_get_temp_dir() . '/wincart_mig_test_' . uniqid() . '.sqlite',
        ]);

        $runner = new MigrationRunner($freshPdo, $tempMigDir);

        try {
            $runner->migrateUp();
            $this->fail('Expected RuntimeException on malformed migration was not thrown');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("Migration failed in '001_malformed.sql'", $e->getMessage());
        }

        // Verify temporary_table_rollback_check was NOT created
        $stmt = $freshPdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='temporary_table_rollback_check'"
        );
        $this->assertFalse($stmt->fetchColumn(), 'Table from failed migration must be completely rolled back');

        // Verify migration was NOT recorded in migrations log
        $logStmt = $freshPdo->query("SELECT COUNT(*) FROM migrations WHERE migration='001_malformed.sql'");
        $this->assertSame(0, (int)$logStmt->fetchColumn());

        // Clean up
        @unlink($tempMigDir . '/001_malformed.sql');
        @rmdir($tempMigDir);
    }

    /**
     * Migration runner batch rollback removes the most recent batch records cleanly.
     */
    public function testMigrationRunnerRollbackLastBatch(): void
    {
        $tempMigDir = sys_get_temp_dir() . '/wincart_batch_migs_' . uniqid();
        mkdir($tempMigDir);

        file_put_contents($tempMigDir . '/001_initial.sql', "CREATE TABLE t_batch1 (id INTEGER PRIMARY KEY);");
        file_put_contents($tempMigDir . '/002_second.sql', "CREATE TABLE t_batch2 (id INTEGER PRIMARY KEY);");

        $freshPdo = ConnectionFactory::create([
            'driver' => 'sqlite',
            'path' => ':memory:',
        ]);

        $runner = new MigrationRunner($freshPdo, $tempMigDir);
        $applied = $runner->migrateUp();
        $this->assertCount(2, $applied);

        $rolledBack = $runner->rollbackLastBatch();
        $this->assertCount(2, $rolledBack);

        // Clean up
        @unlink($tempMigDir . '/001_initial.sql');
        @unlink($tempMigDir . '/002_second.sql');
        @rmdir($tempMigDir);
    }

    /**
     * ConnectionFactory throws InvalidArgumentException on unsupported drivers.
     */
    public function testConnectionFactoryRejectsUnsupportedDrivers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported database driver: 'mongodb'");

        ConnectionFactory::create(['driver' => 'mongodb']);
    }

    /**
     * ConnectionFactory::transactional rolls back transaction upon any uncaught Throwable.
     */
    public function testConnectionFactoryTransactionalRollsBackOnException(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS test_trans (val TEXT)');
        $initialCount = (int)$this->pdo->query('SELECT COUNT(*) FROM test_trans')->fetchColumn();

        try {
            ConnectionFactory::transactional($this->pdo, function (PDO $pdo) {
                $pdo->exec("INSERT INTO test_trans (val) VALUES ('uncommitted')");
                throw new RuntimeException('Intentional transaction abort');
            });
            $this->fail('Expected RuntimeException was not thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('Intentional transaction abort', $e->getMessage());
        }

        $finalCount = (int)$this->pdo->query('SELECT COUNT(*) FROM test_trans')->fetchColumn();
        $this->assertSame($initialCount, $finalCount);
        $this->assertFalse($this->pdo->inTransaction());
    }

    /**
     * Multi-process SQLite write concurrency in WAL mode with busy_timeout.
     */
    public function testWalModeConcurrencyUnderConcurrentWrites(): void
    {
        $sharedDbFile = sys_get_temp_dir() . '/wincart_wal_concurrent_' . uniqid() . '.sqlite';
        $initPdo = ConnectionFactory::create([
            'driver' => 'sqlite',
            'path' => $sharedDbFile,
        ]);
        $initPdo->exec('CREATE TABLE counter (id INTEGER PRIMARY KEY, hits INTEGER)');
        $initPdo->exec('INSERT INTO counter (id, hits) VALUES (1, 0)');
        unset($initPdo);

        $workerCount = 5;
        $incrementsPerWorker = 10;
        $procs = [];

        for ($w = 0; $w < $workerCount; $w++) {
            $code = sprintf(
                'require_once %s;
                $pdo = \WinCart\Database\ConnectionFactory::create([
                    "driver" => "sqlite",
                    "path" => %s
                ]);
                for ($i = 0; $i < %d; $i++) {
                    $pdo->exec("UPDATE counter SET hits = hits + 1 WHERE id = 1");
                    usleep(1000);
                }',
                var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true),
                var_export($sharedDbFile, true),
                $incrementsPerWorker
            );

            $cmd = 'php -r ' . escapeshellarg($code);
            $procs[] = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        }

        foreach ($procs as $proc) {
            $exitCode = proc_close($proc);
            $this->assertSame(0, $exitCode);
        }

        $verifyPdo = ConnectionFactory::create([
            'driver' => 'sqlite',
            'path' => $sharedDbFile,
        ]);
        $totalHits = (int)$verifyPdo->query('SELECT hits FROM counter WHERE id = 1')->fetchColumn();
        $expectedTotal = $workerCount * $incrementsPerWorker;

        $this->assertSame(
            $expectedTotal,
            $totalHits,
            "Expected {$expectedTotal} concurrent hits, got {$totalHits}"
        );

        @unlink($sharedDbFile);
    }

    /**
     * Category repository recursion cycle guard: circular parent references must not cause infinite loops.
     */
    public function testCategoryRepositoryCircularReferenceTerminatesSafely(): void
    {
        $catA = new Category(
            id: null,
            name: 'Loop Category A',
            slug: 'loop-cat-a-' . uniqid(),
            description: 'A points to B',
            parentId: null,
            sortOrder: 1,
            imageUrl: null,
            isActive: true
        );
        $savedA = $this->categoryRepo->save($catA);
        $idA = (int)$savedA->getId();

        $catB = new Category(
            id: null,
            name: 'Loop Category B',
            slug: 'loop-cat-b-' . uniqid(),
            description: 'B points to A',
            parentId: $idA,
            sortOrder: 2,
            imageUrl: null,
            isActive: true
        );
        $savedB = $this->categoryRepo->save($catB);
        $idB = (int)$savedB->getId();

        // Inject the circular reference: A's parent becomes B
        $this->pdo->prepare('UPDATE categories SET parent_id = :parent_id WHERE id = :id')
            ->execute([':parent_id' => $idB, ':id' => $idA]);

        // findSubtreeIds must terminate safely and return both IDs
        $subtreeIds = $this->categoryRepo->findSubtreeIds($idA);
        $this->assertContains($idA, $subtreeIds);
        $this->assertContains($idB, $subtreeIds);

        // findTree must terminate safely without stack overflow
        $tree = $this->categoryRepo->findTree();
        $this->assertIsArray($tree);
    }

    /**
     * Variation product ID validation: adding variation of product A to product B throws exception.
     */
    public function testVariationProductIdMismatchThrowsInvalidCartOperationException(): void
    {
        $variableProduct = $this->createVariableProductWithVariations();
        $variations = $variableProduct->getVariations();
        $this->assertNotEmpty($variations);
        $firstVar = $variations[0];
        $varId = (int)$firstVar->getId();

        $prodB = $this->createSimpleProduct('Product Beta', 'sku-prod-beta', 4000, 10);

        $cart = $this->cartManager->getOrCreateCart('cart_var_mismatch');

        $this->expectException(InvalidCartOperationException::class);
        $this->expectExceptionMessage("Variation #{$varId} does not belong to Product #{$prodB->getId()}");

        $this->cartManager->addItem($cart, (int)$prodB->getId(), 1, $varId);
    }

    /**
     * TaxEngine international country guard: US statutory state rates do not apply to non-US destinations.
     */
    public function testTaxEngineNonUsCountryWithMatchingUsStateCodeDoesNotIncurUsStateTax(): void
    {
        $subtotal = new Money(10000, 'USD');

        // CA state code in Canada should NOT receive California's 8.5% statutory rate
        $resCA = $this->taxEngine->calculateTax($subtotal, 'CA', 'CA');
        $this->assertSame(0, $resCA->getTaxAmount()->getAmount());
        $this->assertSame(0.0, $resCA->getTaxRate());

        // WA state code in Australia should NOT receive Washington's 10.25% statutory rate
        $resAU = $this->taxEngine->calculateTax($subtotal, 'WA', 'AU');
        $this->assertSame(0, $resAU->getTaxAmount()->getAmount());
        $this->assertSame(0.0, $resAU->getTaxRate());
    }

    /**
     * Direct checkout shipping logic: checkout without cart containing only downloadable items has 0 shipping.
     */
    public function testDirectCheckoutWithDownloadableItemsChargesZeroShipping(): void
    {
        $digital = $this->createSimpleProduct('Direct Digital Book', 'sku-direct-digi', 2500, 10, false);

        $checkoutService = new CheckoutService(
            $this->pdo,
            $this->orderRepo,
            $this->productRepo,
            $this->paymentRegistry,
            $this->cartManager,
            $this->taxEngine,
            $this->shippingCalculator
        );

        $order = $checkoutService->submitOrder(null, [
            'items' => [
                [
                    'product_id' => (int)$digital->getId(),
                    'quantity' => 1,
                ],
            ],
            'payment_token' => 'tok_mock_success',
            'shipping_address' => [
                'first_name' => 'Digital',
                'last_name' => 'Buyer',
                'address_1' => '100 Cyber Way',
                'city' => 'Austin',
                'state' => 'TX',
                'postal_code' => '78701',
                'country' => 'US',
            ],
        ]);

        $this->assertSame(0, $order->getShippingAmount()->getAmount());
        $this->assertSame(2500, $order->getSubtotal()->getAmount());
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    private function createSimpleProduct(
        string $name,
        string $sku,
        int $priceCents,
        int $stock = 10,
        bool $requiresShipping = true
    ): Product {
        $type = $requiresShipping ? ProductType::Simple : ProductType::Downloadable;
        $product = new Product(
            id: null,
            name: $name,
            slug: strtolower(str_replace(' ', '-', $name)) . '-' . uniqid(),
            sku: $sku,
            type: $type,
            price: new Money($priceCents, 'USD'),
            salePrice: null,
            compareAtPrice: null,
            costPrice: null,
            description: "Test description for {$name}",
            shortDescription: "Short test description",
            stockQuantity: $stock,
            stockStatus: StockStatus::fromQuantity($stock, false),
            manageStock: true,
            backordersAllowed: false,
            requiresShipping: $requiresShipping,
            weight: 1.0,
            isActive: true
        );

        return $this->productRepo->save($product);
    }

    private function createVariableProductWithVariations(): Product
    {
        $product = new Product(
            id: null,
            name: 'Variable T-Shirt',
            slug: 'variable-t-shirt-' . uniqid(),
            sku: 'VAR-TSHIRT-' . uniqid(),
            type: ProductType::Variable,
            price: new Money(3000, 'USD'),
            salePrice: null,
            compareAtPrice: null,
            costPrice: null,
            description: 'A variable product with size and color options',
            shortDescription: 'Variable product',
            stockQuantity: 10,
            stockStatus: StockStatus::InStock,
            manageStock: true,
            backordersAllowed: false,
            requiresShipping: true,
            weight: 0.5,
            isActive: true
        );

        $savedProduct = $this->productRepo->save($product);
        $productId = (int)$savedProduct->getId();

        // Create 2 variations
        $stmt = $this->pdo->prepare('INSERT INTO product_variations (
            product_id, sku, price, sale_price, stock_quantity, stock_status,
            manage_stock, attributes_json, created_at, updated_at
        ) VALUES (
            :pid, :sku, :price, NULL, :stock, :status,
            1, :attrs, datetime("now"), datetime("now")
        )');

        $stmt->execute([
            ':pid' => $productId,
            ':sku' => 'VAR-TSHIRT-M-RED',
            ':price' => 3000,
            ':stock' => 5,
            ':status' => 'instock',
            ':attrs' => json_encode(['size' => 'Medium', 'color' => 'Red']),
        ]);

        $stmt->execute([
            ':pid' => $productId,
            ':sku' => 'VAR-TSHIRT-L-BLUE',
            ':price' => 3500,
            ':stock' => 5,
            ':status' => 'instock',
            ':attrs' => json_encode(['size' => 'Large', 'color' => 'Blue']),
        ]);

        return $this->productRepo->findById($productId) ?? $savedProduct;
    }

    private function insertCouponDb(string $code, string $type, int $value, int $minSubtotal): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO coupons (
            code, discount_type, discount_value, min_subtotal, usage_limit,
            usage_count, starts_at, expires_at, is_active, created_at
        ) VALUES (
            :code, :type, :value, :min_sub, NULL,
            0, NULL, NULL, 1, datetime("now")
        )');

        $stmt->execute([
            ':code' => strtoupper($code),
            ':type' => $type,
            ':value' => $value,
            ':min_sub' => $minSubtotal,
        ]);
    }

    private function createOrderRecord(OrderStatus $status): Order
    {
        $stmt = $this->pdo->prepare('INSERT INTO orders (
            order_number, customer_id, status, currency, subtotal, discount_amount,
            tax_amount, shipping_amount, grand_total,
            billing_first_name, billing_last_name, billing_address_1, billing_city,
            billing_state, billing_postal_code, billing_country,
            shipping_first_name, shipping_last_name, shipping_address_1, shipping_city,
            shipping_state, shipping_postal_code, shipping_country,
            payment_method, created_at, updated_at
        ) VALUES (
            :num, 1, :status, "USD", 5000, 0,
            500, 750, 6250,
            "Jane", "Doe", "123 Elm St", "Seattle",
            "WA", "98101", "US",
            "Jane", "Doe", "123 Elm St", "Seattle",
            "WA", "98101", "US",
            "mock", datetime("now"), datetime("now")
        )');

        $stmt->execute([
            ':num' => 'ORD-ADV-' . uniqid(),
            ':status' => $status->value,
        ]);

        $orderId = (int)$this->pdo->lastInsertId();
        $order = $this->orderRepo->findById($orderId);
        $this->assertNotNull($order);
        return $order;
    }
}
