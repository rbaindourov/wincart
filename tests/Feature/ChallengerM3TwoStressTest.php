<?php

declare(strict_types=1);

namespace WinCart\Tests\Feature;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use WinCart\Cart\Pricing\Coupon;
use WinCart\Cart\Pricing\CouponCalculator;
use WinCart\Cart\Pricing\ShippingCalculator;
use WinCart\Cart\Pricing\TaxEngine;
use WinCart\Cart\Services\PdoCartManager;
use WinCart\Catalog\Models\Product;
use WinCart\Catalog\Repositories\PdoProductRepository;
use WinCart\Checkout\CheckoutService;
use WinCart\Checkout\Exceptions\InvalidOrderStateTransitionException;
use WinCart\Checkout\Order;
use WinCart\Checkout\OrderStateMachine;
use WinCart\Checkout\PdoOrderRepository;
use WinCart\Common\Address;
use WinCart\Common\Money;
use WinCart\Common\OrderStatus;
use WinCart\Common\ProductType;
use WinCart\Common\StockStatus;
use WinCart\Database\ConnectionFactory;
use WinCart\Database\MigrationRunner;
use WinCart\Http\Kernel;
use WinCart\Payment\MockPaymentGateway;
use WinCart\Payment\PaymentGatewayRegistry;

/**
 * Challenger M3-2 Empirical Challenge & Stress Test Suite.
 *
 * Covers:
 * 1. FSM state transition mutations & RFC 7807 problem details
 * 2. Compound pricing math invariance & zero IEEE-754 drift
 * 3. Security lockfile invariance (302 redirect vs 403 seal)
 * 4. Guest-to-customer cart auto-merge & stock clamping
 */
class ChallengerM3TwoStressTest extends TestCase
{
    private PDO $pdo;
    private PdoProductRepository $productRepo;
    private PdoOrderRepository $orderRepo;
    private OrderStateMachine $stateMachine;
    private PdoCartManager $cartManager;
    private TaxEngine $taxEngine;
    private ShippingCalculator $shippingCalculator;
    private Kernel $kernel;
    private string $tempLockfile;
    private string $tempDbFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDbFile = sys_get_temp_dir() . '/wincart_test_db_' . uniqid() . '.sqlite';
        $this->pdo = ConnectionFactory::create([
            'driver' => 'sqlite',
            'path' => $this->tempDbFile,
        ]);
        $runner = new MigrationRunner($this->pdo);
        $runner->migrateUp();

        $this->pdo->exec('INSERT INTO customers (id, email, password_hash, first_name, last_name)
            VALUES (1, "jane@example.com", "hash", "Jane", "Doe")');

        $this->productRepo = new PdoProductRepository($this->pdo);
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

        $this->tempLockfile = sys_get_temp_dir() . '/wincart_installed_' . uniqid() . '.lock';
        touch($this->tempLockfile);

        $this->kernel = new Kernel([
            'DB_DATABASE' => $this->tempDbFile,
            'INSTALL_LOCK_PATH' => $this->tempLockfile,
        ]);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempLockfile)) {
            @unlink($this->tempLockfile);
        }
        if (file_exists($this->tempDbFile)) {
            @unlink($this->tempDbFile);
        }
        parent::tearDown();
    }

    private function createOrderWithStatus(OrderStatus $status, int $id = 100): Order
    {
        $stmt = $this->pdo->prepare('INSERT INTO orders (
            id, order_number, customer_id, status, currency, subtotal, discount_amount,
            tax_amount, shipping_amount, grand_total,
            billing_first_name, billing_last_name, billing_address_1, billing_city,
            billing_state, billing_postal_code, billing_country,
            shipping_first_name, shipping_last_name, shipping_address_1, shipping_city,
            shipping_state, shipping_postal_code, shipping_country,
            payment_method, created_at, updated_at
        ) VALUES (
            :id, :num, 1, :status, "USD", 5000, 0,
            500, 750, 6250,
            "Jane", "Doe", "123 Elm St", "Seattle",
            "WA", "98101", "US",
            "Jane", "Doe", "123 Elm St", "Seattle",
            "WA", "98101", "US",
            "mock", datetime("now"), datetime("now")
        )');
        $stmt->execute([
            ':id' => $id,
            ':num' => 'ORD-' . $id . '-' . uniqid(),
            ':status' => $status->value,
        ]);

        $order = $this->orderRepo->findById($id);
        $this->assertNotNull($order);
        return $order;
    }

    // =========================================================================
    // 1. FSM STATE TRANSITION MUTATION TESTING
    // =========================================================================

    /**
     * Test all invalid transitions specified in mission:
     * - Completed -> Pending
     * - Completed -> Processing
     * - Refunded -> Cancelled
     * - Cancelled -> Completed
     * - Refunded -> Processing
     *
     * Must return 422 Unprocessable Entity with RFC 7807 title 'Invalid Order State Transition'.
     */
    public function testInvalidFsmTransitionsReturn422WithRfc7807Title(): void
    {
        $matrix = [
            ['from' => OrderStatus::Completed, 'to' => 'pending', 'label' => 'Completed -> Pending'],
            ['from' => OrderStatus::Completed, 'to' => 'processing', 'label' => 'Completed -> Processing'],
            ['from' => OrderStatus::Refunded, 'to' => 'cancelled', 'label' => 'Refunded -> Cancelled'],
            ['from' => OrderStatus::Cancelled, 'to' => 'completed', 'label' => 'Cancelled -> Completed'],
            ['from' => OrderStatus::Refunded, 'to' => 'processing', 'label' => 'Refunded -> Processing'],
            ['from' => OrderStatus::Cancelled, 'to' => 'pending', 'label' => 'Cancelled -> Pending'],
            ['from' => OrderStatus::Refunded, 'to' => 'pending', 'label' => 'Refunded -> Pending'],
        ];

        $orderId = 200;
        foreach ($matrix as $case) {
            $orderId++;
            $this->createOrderWithStatus($case['from'], $orderId);

            $response = $this->kernel->handleRequest(
                'PATCH',
                "/api/v1/orders/{$orderId}/status",
                (string)json_encode(['status' => $case['to']]),
                ['Content-Type' => 'application/json']
            );

            $this->assertSame(
                422,
                $response->getStatusCode(),
                "Failed on {$case['label']}: Expected HTTP 422, got {$response->getStatusCode()}"
            );

            $payload = json_decode($response->getBody(), true);
            $this->assertIsArray($payload);
            $this->assertSame(
                'Invalid Order State Transition',
                $payload['title'] ?? null,
                "Failed on {$case['label']}: RFC 7807 title mismatch: " . ($payload['title'] ?? 'none')
            );
            $this->assertSame(422, $payload['status'] ?? null);
            $this->assertStringContainsString('Cannot transition', $payload['detail'] ?? '');
        }
    }

    /**
     * Test direct OrderStateMachine exception throwing for invalid transitions.
     */
    public function testOrderStateMachineThrowsInvalidOrderStateTransitionException(): void
    {
        $order = $this->createOrderWithStatus(OrderStatus::Completed, 301);

        $this->expectException(InvalidOrderStateTransitionException::class);
        $this->stateMachine->transition($order, OrderStatus::Pending);
    }

    /**
     * Test idempotent self-transitions:
     * - Refunded -> Refunded
     * - Cancelled -> Cancelled
     * - Completed -> Completed
     *
     * Must return 200 OK with zero side effects.
     */
    public function testIdempotentSelfTransitionsReturn200WithZeroSideEffects(): void
    {
        $cases = [
            ['status' => OrderStatus::Refunded, 'to' => 'refunded', 'id' => 401],
            ['status' => OrderStatus::Cancelled, 'to' => 'cancelled', 'id' => 402],
            ['status' => OrderStatus::Completed, 'to' => 'completed', 'id' => 403],
        ];

        foreach ($cases as $c) {
            $this->createOrderWithStatus($c['status'], $c['id']);

            // Self-transition via HTTP API
            $response = $this->kernel->handleRequest(
                'PATCH',
                "/api/v1/orders/{$c['id']}/status",
                (string)json_encode(['status' => $c['to']]),
                ['Content-Type' => 'application/json']
            );

            $this->assertSame(
                200,
                $response->getStatusCode(),
                "Self-transition {$c['to']} -> {$c['to']} should return HTTP 200 OK"
            );

            $payload = json_decode($response->getBody(), true);
            $this->assertSame($c['to'], $payload['status']);

            // Verify order in database still has original status
            $reloaded = $this->orderRepo->findById($c['id']);
            $this->assertNotNull($reloaded);
            $this->assertSame($c['status'], $reloaded->getStatus());
        }
    }

    // =========================================================================
    // 2. COMPOUND PRICING MATH INVARIANCE
    // =========================================================================

    /**
     * Test coupons with fractional percentages and PHP_ROUND_HALF_UP rounding.
     */
    public function testCouponPercentageRoundingHalfUp(): void
    {
        // 15% discount on $99.99 (9999 cents)
        // 9999 * 0.15 = 1499.85 -> 1500 cents ($15.00)
        $coupon15 = new Coupon(
            id: 1,
            code: 'PCT15',
            discountType: 'percentage',
            discountValue: 15
        );
        $discount = CouponCalculator::calculateDiscount($coupon15, new Money(9999, 'USD'));
        $this->assertSame(1500, $discount->getAmount());

        // Test exact half-round: 1 cent subtotal with 50% discount -> 0.5 cents -> 1 cent
        $coupon50 = new Coupon(
            id: 3,
            code: 'HALF',
            discountType: 'percentage',
            discountValue: 50
        );
        $halfRound = CouponCalculator::calculateDiscount($coupon50, new Money(1, 'USD'));
        $this->assertSame(1, $halfRound->getAmount(), '0.5 cents must round UP to 1 cent under PHP_ROUND_HALF_UP');

        // 33% discount on $1.00 (100 cents): 100 * 0.33 = 33 cents
        $coupon33 = new Coupon(
            id: 4,
            code: 'THIRD',
            discountType: 'percentage',
            discountValue: 33
        );
        $disc33 = CouponCalculator::calculateDiscount($coupon33, new Money(100, 'USD'));
        $this->assertSame(33, $disc33->getAmount());
    }

    /**
     * Test destination tax zones on discounted subtotal with compound shipping and zero IEEE-754 drift.
     */
    public function testCompoundPricingMathAndZeroIeee754Drift(): void
    {
        // Configure tax zone: WA state (10.25%, $7.50 flat shipping, $150.00 free shipping threshold)
        $this->pdo->exec(
            "INSERT INTO tax_zones (country, state, name, rate, flat_shipping_rate, free_shipping_threshold)
             VALUES ('US', 'WA', 'Washington', 0.1025, 750, 15000)"
        );

        // Insert coupon SAVE20 (20% off)
        $this->pdo->exec(
            "INSERT INTO coupons (code, discount_type, discount_value, min_subtotal, is_active)
             VALUES ('SAVE20', 'percentage', 20, 0, 1)"
        );

        // Product A: $49.99 (4999 cents) x 3 = $149.97 (14997 cents)
        $prodA = $this->productRepo->save(new Product(
            id: null,
            name: 'Widget Alpha',
            slug: 'widget-alpha',
            sku: 'WGT-A',
            type: ProductType::Simple,
            price: new Money(4999, 'USD'),
            stockQuantity: 10
        ));

        $cart = $this->cartManager->getOrCreateCart('token_pricing_test');
        $this->cartManager->addItem($cart, (int)$prodA->getId(), 3);
        $cart = $this->cartManager->applyCoupon($cart, 'SAVE20');

        $totals = $this->cartManager->calculateTotals($cart, 'WA');

        // Subtotal: 3 * 4999 = 14997 cents ($149.97)
        $this->assertSame(14997, $totals->getSubtotal()->getAmount());

        // Discount: 20% of 14997 = 2999.4 -> rounds half-up to 2999 cents ($29.99)
        $this->assertSame(2999, $totals->getDiscountAmount()->getAmount());

        // Discounted Subtotal: 14997 - 2999 = 11998 cents ($119.98)
        $this->assertSame(11998, $totals->getDiscountedSubtotal()->getAmount());

        // Tax: 10.25% on 11998 = 1229.795 -> rounds half-up to 1230 cents ($12.30)
        $this->assertSame(1230, $totals->getTaxAmount()->getAmount());

        // Shipping: Discounted subtotal (11998) < threshold (15000), so flat rate $7.50 (750 cents)
        $this->assertSame(750, $totals->getShippingAmount()->getAmount());

        // Grand Total: 11998 + 1230 + 750 = 13978 cents ($139.78)
        $this->assertSame(13978, $totals->getGrandTotal()->getAmount());
        $this->assertSame('$139.78', $totals->getGrandTotal()->format());

        // Invariance check: GrandTotal == DiscountedSubtotal + Tax + Shipping
        $recomputed = $totals->getDiscountedSubtotal()
            ->add($totals->getTaxAmount())
            ->add($totals->getShippingAmount());
        $this->assertTrue(
            $totals->getGrandTotal()->equals($recomputed),
            'Grand total must strictly match sum of components without 1-cent IEEE-754 drift'
        );
    }

    /**
     * Test free shipping threshold boundary:
     * - Threshold is $150.00 (15000 cents).
     * - Cart at exactly $150.00 discounted subtotal qualifies for free shipping.
     * - Cart at $149.99 discounted subtotal incurs flat shipping ($7.50).
     */
    public function testShippingThresholdBoundary(): void
    {
        $this->pdo->exec(
            "INSERT INTO tax_zones (country, state, name, rate, flat_shipping_rate, free_shipping_threshold)
             VALUES ('US', 'OR', 'Oregon', 0.0000, 750, 15000)"
        );

        $prod = $this->productRepo->save(new Product(
            id: null,
            name: 'Boundary Widget',
            slug: 'boundary-widget',
            sku: 'BND-01',
            type: ProductType::Simple,
            price: new Money(1, 'USD'),
            stockQuantity: 20000
        ));

        // Subtotal = 14999 ($149.99): below threshold
        $cartBelow = $this->cartManager->getOrCreateCart('token_below');
        $this->cartManager->addItem($cartBelow, (int)$prod->getId(), 14999);
        $cartBelow = $this->cartManager->getCartByToken('token_below');
        $totalsBelow = $this->cartManager->calculateTotals($cartBelow, 'OR');
        $this->assertSame(
            750,
            $totalsBelow->getShippingAmount()->getAmount(),
            'Cart at $149.99 must incur shipping'
        );

        // Subtotal = 15000 ($150.00): exactly at threshold
        $cartExact = $this->cartManager->getOrCreateCart('token_exact');
        $this->cartManager->addItem($cartExact, (int)$prod->getId(), 15000);
        $cartExact = $this->cartManager->getCartByToken('token_exact');
        $totalsExact = $this->cartManager->calculateTotals($cartExact, 'OR');
        $this->assertSame(
            0,
            $totalsExact->getShippingAmount()->getAmount(),
            'Cart at $150.00 must qualify for free shipping'
        );
    }

    // =========================================================================
    // 3. SECURITY LOCKFILE INVARIANCE
    // =========================================================================

    /**
     * Test uninstalled state (lockfile absent):
     * Storefront requests must redirect to /install with HTTP 302.
     */
    public function testUninstalledStateRedirectsToInstallWith302(): void
    {
        $uninstalledLockPath = sys_get_temp_dir() . '/wincart_absent_' . uniqid() . '.lock';
        if (file_exists($uninstalledLockPath)) {
            unlink($uninstalledLockPath);
        }

        $uninstalledKernel = new Kernel([
            'DB_DATABASE' => $this->tempDbFile,
            'INSTALL_LOCK_PATH' => $uninstalledLockPath,
        ]);

        $paths = ['/', '/cart', '/checkout', '/admin', '/api/v1/products'];
        foreach ($paths as $p) {
            $response = $uninstalledKernel->handleRequest('GET', $p);
            $this->assertSame(302, $response->getStatusCode(), "Path {$p} should 302 redirect when uninstalled");
            $this->assertSame(['/install'], $response->getHeaders()['Location'] ?? []);
        }
    }

    /**
     * Test installed state (lockfile present):
     * All requests to /install must be sealed with HTTP 403 Forbidden.
     */
    public function testInstalledStateSealsInstallWith403Forbidden(): void
    {
        $this->assertFileExists($this->tempLockfile);

        $installPaths = ['/install', '/install/', '/install/database', '/install/admin', '/install/step2'];
        foreach ($installPaths as $p) {
            $response = $this->kernel->handleRequest('GET', $p);
            $this->assertSame(403, $response->getStatusCode(), "Path {$p} must be sealed with 403 Forbidden");
            $this->assertStringContainsString('Installation locked', $response->getBody());
        }
    }

    // =========================================================================
    // 4. GUEST-TO-CUSTOMER CART AUTO-MERGE
    // =========================================================================

    /**
     * Test guest-to-customer cart auto-merge:
     * - Unique items append to customer cart
     * - Duplicate items combine quantities
     * - Coupons transfer from guest to customer
     * - Guest cart header is deleted from carts table
     */
    public function testGuestCartAutoMergeUniqueItemsCouponsAndHeaderDeletion(): void
    {
        $prodA = $this->productRepo->save(new Product(
            id: null,
            name: 'Item Alpha',
            slug: 'item-alpha',
            sku: 'ALP-01',
            type: ProductType::Simple,
            price: new Money(1000, 'USD'),
            stockQuantity: 10
        ));

        $prodB = $this->productRepo->save(new Product(
            id: null,
            name: 'Item Beta',
            slug: 'item-beta',
            sku: 'BET-01',
            type: ProductType::Simple,
            price: new Money(2000, 'USD'),
            stockQuantity: 10
        ));

        $this->pdo->exec("INSERT INTO customers (id, email, password_hash, first_name, last_name)
            VALUES (999, 'merge_test@example.com', 'hash', 'Test', 'User')");

        $this->pdo->exec("INSERT INTO coupons (code, discount_type, discount_value, min_subtotal, is_active)
            VALUES ('TRANSFER10', 'percentage', 10, 0, 1)");

        // Customer cart has Item A (qty 2)
        $custCart = $this->cartManager->getOrCreateCart('token_customer_999', 999);
        $this->cartManager->addItem($custCart, (int)$prodA->getId(), 2);

        // Guest cart has Item B (qty 3) and coupon TRANSFER10
        $guestCart = $this->cartManager->getOrCreateCart('token_guest_999');
        $this->cartManager->addItem($guestCart, (int)$prodB->getId(), 3);
        $this->cartManager->applyCoupon($guestCart, 'TRANSFER10');

        $guestCartId = $guestCart->getId();

        // Perform merge
        $merged = $this->cartManager->mergeGuestCartIntoCustomerCart('token_guest_999', 999);

        // Verify unique items appended: Customer cart now has Item A (2) and Item B (3)
        $itemA = $merged->findItemByProduct((int)$prodA->getId());
        $itemB = $merged->findItemByProduct((int)$prodB->getId());

        $this->assertNotNull($itemA, 'Customer item A must exist');
        $this->assertSame(2, $itemA->getQuantity());

        $this->assertNotNull($itemB, 'Unique guest item B must append');
        $this->assertSame(3, $itemB->getQuantity());

        // Verify coupon transferred
        $this->assertSame('TRANSFER10', $merged->getCouponCode(), 'Guest coupon must transfer to customer cart');

        // Verify guest cart header row was deleted
        $this->assertNull($this->cartManager->getCartByToken('token_guest_999'));
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM carts WHERE id = :id');
        $stmt->execute([':id' => $guestCartId]);
        $this->assertSame(0, (int)$stmt->fetchColumn(), 'Guest cart record in carts table must be deleted');
    }

    /**
     * EMPIRICAL STRESS TEST FOR MISSION REQUIREMENT:
     * "verify duplicate items combine quantities clamped to available stock"
     *
     * Setup:
     * Product has available stock = 4.
     * Customer cart has quantity = 2.
     * Guest cart has quantity = 3.
     * Combined requested quantity = 5.
     * Available stock = 4.
     *
     * Expected behavior: Quantity is clamped to available stock (4).
     */
    public function testGuestCartAutoMergeDuplicateItemsClampedToAvailableStock(): void
    {
        $prodLimited = $this->productRepo->save(new Product(
            id: null,
            name: 'Limited Stock Headphones',
            slug: 'limited-headphones',
            sku: 'LTD-HP-01',
            type: ProductType::Simple,
            price: new Money(15000, 'USD'),
            stockQuantity: 4,
            stockStatus: StockStatus::InStock
        ));

        $this->pdo->exec("INSERT INTO customers (id, email, password_hash, first_name, last_name)
            VALUES (8888, 'clamping_test@example.com', 'hash', 'Clamp', 'Tester')");

        // Customer cart has quantity 2
        $custCart = $this->cartManager->getOrCreateCart('token_cust_clamp', 8888);
        $this->cartManager->addItem($custCart, (int)$prodLimited->getId(), 2);

        // Guest cart has quantity 3
        $guestCart = $this->cartManager->getOrCreateCart('token_guest_clamp');
        $this->cartManager->addItem($guestCart, (int)$prodLimited->getId(), 3);

        // Auto-merge guest cart into customer cart
        $merged = $this->cartManager->mergeGuestCartIntoCustomerCart('token_guest_clamp', 8888);

        $mergedItem = $merged->findItemByProduct((int)$prodLimited->getId());
        $this->assertNotNull($mergedItem);

        // Assert quantity is clamped to available stock (4)
        $this->assertSame(
            4,
            $mergedItem->getQuantity(),
            sprintf(
                'Duplicate items on auto-merge must be clamped to stock (%d), but received quantity=%d',
                $prodLimited->getStockQuantity(),
                $mergedItem->getQuantity()
            )
        );
    }
}
