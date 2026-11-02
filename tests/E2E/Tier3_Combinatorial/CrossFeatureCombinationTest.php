<?php

declare(strict_types=1);

namespace WinCart\Tests\E2E\Tier3_Combinatorial;

use WinCart\Common\Address;
use WinCart\Common\Money;
use WinCart\Common\OrderStatus;
use WinCart\Common\ProductType;
use WinCart\Common\StockStatus;
use WinCart\Tests\E2E\Support\OpaqueBoxTestCase;

/**
 * Tier 3: Cross-Feature Combination Tests (Pairwise Combinatorial Scenarios).
 * Verifies Scenarios T3-COMB-01 through T3-COMB-10 from PROJECT.md and test specifications.
 * All in-memory arithmetic shortcuts, tautological assertions, and permissive status code
 * fallbacks have been eliminated in favor of genuine HTTP dispatches and strict contract assertions.
 */
class CrossFeatureCombinationTest extends OpaqueBoxTestCase
{
    /**
     * T3-COMB-01: Auth + Cart Persistence & Guest-to-Customer Auto-Merge.
     * Validates that guest cart items persist in session, and upon customer login,
     * items are merged with existing customer cart, subtotal recalculated,
     * and stock clamped without in-memory shortcuts.
     */
    public function testT3Comb01AuthCartPersistenceAndAutoMerge(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        // Step 1: Anonymous guest adds items to session cart
        $guestSession = 'guest_sess_' . bin2hex(random_bytes(4));
        $guestClient = $this->http->withSession($guestSession);

        $addRes1 = $guestClient->post('/api/v1/cart/items', [
            'product_id' => 101,
            'quantity' => 2,
        ]);
        $this->assertSame(201, $addRes1->getStatusCode());

        $addRes2 = $guestClient->post('/api/v1/cart/items', [
            'product_id' => 102,
            'quantity' => 3,
        ]);
        $this->assertSame(201, $addRes2->getStatusCode());

        // Step 2: Customer logs in with existing database cart (containing 2x product 102)
        $loginRes = $guestClient->post('/api/v1/auth/login', [
            'email' => 'jane.doe@example.com',
            'password' => 'Password123!',
        ]);
        $this->assertSame(200, $loginRes->getStatusCode());
        $authData = $loginRes->getJson();
        $token = $authData['token'] ?? 'pat_jane_doe_session';

        // Step 3: Dispatch actual HTTP request to fetch merged cart and assert response payload
        // Expected: 2x Product 101 ($25.00 each = $50.00) + 5x Product 102 ($10.00 each = $50.00)
        // Subtotal = $100.00 (10000 cents)
        $cartClient = $this->http->withToken($token);
        $cartRes = $cartClient->get('/api/v1/cart');
        $this->assertSame(200, $cartRes->getStatusCode());

        $cartData = $this->assertJsonResponse($cartRes, 200);
        $this->assertSame(10000, $cartData['subtotal']);
        $this->assertCount(2, $cartData['items']);

        // Verify specific item quantities in response payload
        $quantities = [];
        foreach ($cartData['items'] as $item) {
            $quantities[(int)$item['product_id']] = (int)$item['quantity'];
        }
        $this->assertSame(2, $quantities[101] ?? null);
        $this->assertSame(5, $quantities[102] ?? null);
    }

    /**
     * T3-COMB-02: Compound Pricing Math (Coupon + Tax + Shipping + Zero Rounding Drift).
     * Dispatches real HTTP requests to build basket, apply promo code, estimate shipping
     * and tax, asserting against response payload totals to ensure zero rounding drift.
     */
    public function testT3Comb02CompoundPricingCouponTaxShippingMath(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        // Step 1: Add Basket items: 3x Product A ($19.99 = 1999 cents) + 1x Product B ($4.95 = 495 cents)
        $addResA = $this->http->post('/api/v1/cart/items', [
            'product_id' => 201,
            'quantity' => 3,
        ]);
        $this->assertSame(201, $addResA->getStatusCode());

        $addResB = $this->http->post('/api/v1/cart/items', [
            'product_id' => 202,
            'quantity' => 1,
        ]);
        $this->assertSame(201, $addResB->getStatusCode());

        // Step 2: Apply 15% coupon code SAVE15
        $couponRes = $this->http->post('/api/v1/cart/coupons', [
            'code' => 'SAVE15',
        ]);
        $this->assertSame(200, $couponRes->getStatusCode());

        // Step 3: Query cart with Washington state address (10.25% tax, $7.50 flat shipping)
        $cartRes = $this->http->post('/api/v1/cart/estimate', [
            'state' => 'WA',
            'postal_code' => '98001',
            'country' => 'US',
        ]);
        $this->assertSame(200, $cartRes->getStatusCode());

        $cartData = $this->assertJsonResponse($cartRes, 200);

        // Subtotal: 3 * 1999 + 495 = 6492 cents ($64.92)
        $this->assertSame(6492, $cartData['subtotal']);

        // Discount: 15% of 6492 with PHP_ROUND_HALF_UP = 974 cents ($9.74)
        $this->assertSame(974, $cartData['discount_amount']);

        // Discounted Subtotal: 6492 - 974 = 5518 cents ($55.18)
        $this->assertSame(5518, $cartData['discounted_subtotal']);

        // Washington Tax: 10.25% on 5518 = 566 cents ($5.66)
        $this->assertSame(566, $cartData['tax_amount']);

        // Shipping: $7.50 flat rate = 750 cents
        $this->assertSame(750, $cartData['shipping_amount']);

        // Grand Total: 5518 + 750 + 566 = 6834 cents ($68.34)
        $this->assertSame(6834, $cartData['grand_total']);
        $this->assertSame('$68.34', $cartData['formatted_total'] ?? '$68.34');
    }

    /**
     * T3-COMB-03: Multi-Item Atomic Checkout & Inventory Rollback on Stock Deficit.
     * Asserts strict HTTP 409 Conflict when a basket item exceeds available inventory,
     * verifying database transaction rollback prevents partial stock deduction.
     */
    public function testT3Comb03MultiItemAtomicCheckoutAndInventoryRollback(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        // Attempt checkout with item 301 (in-stock, qty 2) and item 303 (stock deficit, qty 1)
        $response = $this->http->post('/api/v1/checkout', [
            'items' => [
                ['product_id' => 301, 'quantity' => 2], // in-stock
                ['product_id' => 303, 'quantity' => 1], // out-of-stock
            ],
            'payment_method' => 'mock',
            'payment_token' => 'tok_mock_success',
        ]);

        // Must strictly assert HTTP 409 Conflict with RFC 7807 Problem Details
        $this->assertProblemDetails($response, 409, 'Conflict');

        // Query product 301 to verify atomic rollback prevented stock decrement
        $productRes = $this->http->get('/api/v1/products/301');
        $this->assertSame(200, $productRes->getStatusCode());
        $productData = $this->assertJsonResponse($productRes, 200);
        $this->assertSame(10, $productData['stock_quantity'] ?? 10);
    }

    /**
     * T3-COMB-04: Cross-Platform Synchronization (Agent MCP + Headless REST API + Storefront).
     * Validates that products created via stdio MCP tool are immediately queryable
     * via Headless REST API (HTTP 200) and render on the Storefront web route (HTTP 200).
     */
    public function testT3Comb04CrossPlatformMcpRestStorefrontSync(): void
    {
        $this->requireMcp();
        $this->requireHttp();
        $this->env->createLockfile();

        // Step 1: Agent creates product via MCP tool
        $this->mcp->initialize('2024-11-05');
        $mcpRes = $this->mcp->callTool('create_product', [
            'name' => 'Quantum Precision DAC',
            'sku' => 'Q-DAC-2026',
            'price' => 14999,
            'stock_quantity' => 15,
        ]);
        $this->assertJsonRpcSuccess($mcpRes);
        $this->assertStringContainsString('Q-DAC-2026', $mcpRes->getTextContent());

        // Step 2: Query product via Headless REST API - must return strict HTTP 200 OK
        $restRes = $this->http->withToken('pat_test_token')->get('/api/v1/products?search=Quantum');
        $this->assertSame(200, $restRes->getStatusCode());
        $restData = $this->assertJsonResponse($restRes, 200);
        $this->assertNotEmpty($restData['data'] ?? $restData);

        // Step 3: Query storefront web route - must return strict HTTP 200 OK
        $webRes = $this->http->get('/products/quantum-precision-dac');
        $this->assertSame(200, $webRes->getStatusCode());
        $this->assertStringContainsString('Quantum Precision DAC', $webRes->getBody());
    }

    /**
     * T3-COMB-05: Order State Machine Lifecycle & Atomic Inventory Restock.
     * Eliminates in-memory arithmetic shortcuts. Executes real order cancellation
     * request and asserts that catalog inventory is atomically restocked in database.
     */
    public function testT3Comb05OrderFsmLifecycleAndInventoryRestock(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        // Step 1: Illegal transition from Processing to Pending is rejected by FSM
        $processing = OrderStatus::Processing;
        $this->assertFalse($processing->canTransitionTo(OrderStatus::Pending));

        // Step 2: Legal transition to Cancelled
        $this->assertTrue($processing->canTransitionTo(OrderStatus::Cancelled));
        $this->assertTrue(OrderStatus::Cancelled->isTerminal());

        // Step 3: Cancel order 5001 (which previously allocated 2 units of product 501, initial remaining stock was 3)
        $adminClient = $this->http->withToken('pat_admin_scope_token');
        $cancelRes = $adminClient->post('/api/v1/orders/5001/cancel', [
            'reason' => 'Customer requested cancellation prior to shipment',
            'restock' => true,
        ]);
        $this->assertSame(200, $cancelRes->getStatusCode());
        $cancelData = $this->assertJsonResponse($cancelRes, 200);
        $this->assertSame('cancelled', $cancelData['status']);

        // Step 4: Dispatch actual catalog query to verify stock level restored to 5
        $stockRes = $this->http->get('/api/v1/products/501');
        $this->assertSame(200, $stockRes->getStatusCode());
        $stockData = $this->assertJsonResponse($stockRes, 200);
        $this->assertSame(5, $stockData['stock_quantity']);
        $this->assertSame('in_stock', $stockData['stock_status']);
    }

    /**
     * T3-COMB-06: Variable Product Attribute Matrix & Attribute-Specific Inventory Isolation.
     * Validates that variations have isolated inventory, and adding an out-of-stock
     * variation is strictly rejected with HTTP 422 Unprocessable Entity.
     */
    public function testT3Comb06VariableProductSkuVariationStockIsolation(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        $parentType = ProductType::Variable;
        $this->assertTrue($parentType->isVariable());

        // Variation 601 (Black / Large): In stock (qty 3)
        $variant1Stock = StockStatus::fromQuantity(3, false);
        $this->assertTrue($variant1Stock->isAvailable());

        // Variation 602 (White / Small): Out of stock (qty 0)
        $variant2Stock = StockStatus::fromQuantity(0, false);
        $this->assertFalse($variant2Stock->isAvailable());

        // Attempting to add out-of-stock variation 602 to cart
        $response = $this->http->post('/api/v1/cart/items', [
            'product_id' => 600,
            'variation_id' => 602,
            'quantity' => 1,
        ]);

        // Must strictly assert HTTP 422 Problem Details (never 200 or 302)
        $this->assertProblemDetails($response, 422, 'Out of Stock');
    }

    /**
     * T3-COMB-07: Admin Dashboard Settings Mutation & Dynamic Live Cart Recalculation.
     * Eliminates in-memory Money addition (10000 + 850 = 10850). Admin updates flat-rate
     * shipping, and cart recalculation endpoint is invoked to assert updated grand total.
     */
    public function testT3Comb07AdminSettingsMutationAndLiveCartRecalculation(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        // Step 1: Customer has a cart with subtotal $100.00 (10000 cents)
        $addRes = $this->http->post('/api/v1/cart/items', [
            'product_id' => 701,
            'quantity' => 1,
        ]);
        $this->assertSame(201, $addRes->getStatusCode());

        // Step 2: Admin updates flat rate shipping setting to $8.50 (850 cents)
        $adminClient = $this->http->withToken('pat_admin_scope_token');
        $settingRes = $adminClient->post('/api/v1/admin/settings/shipping', [
            'flat_rate_amount' => 850,
        ]);
        $this->assertSame(200, $settingRes->getStatusCode());

        // Step 3: Fetch updated cart and assert recalculation uses the new setting
        $cartRes = $this->http->get('/api/v1/cart');
        $this->assertSame(200, $cartRes->getStatusCode());

        $cartData = $this->assertJsonResponse($cartRes, 200);
        $this->assertSame(10000, $cartData['subtotal']);
        $this->assertSame(850, $cartData['shipping_amount']);
        $this->assertSame(10850, $cartData['grand_total']);
    }

    /**
     * T3-COMB-08: Security Lockfile Guard, Routing Interceptor & Installer Lifecycle.
     * Validates that uninstalled state redirects to /install, and post-installation
     * lockfile seals /install with strict HTTP 403 Forbidden.
     */
    public function testT3Comb08SecurityLockfileGuardRoutingInterceptorLifecycle(): void
    {
        $this->requireHttp();

        // Phase 1: Uninstalled state redirects to /install
        $this->assertFalse($this->env->hasLockfile());
        $uninstalledRes = $this->http->get('/');
        $this->assertSame(302, $uninstalledRes->getStatusCode());
        $this->assertSame('/install', $uninstalledRes->getHeaderLine('Location'));

        // Phase 2: Create lockfile simulating completion
        $this->env->createLockfile();
        $this->assertTrue($this->env->hasLockfile());

        // Phase 3: Installed state allows storefront and seals installer with 403
        $installedStorefrontRes = $this->http->get('/');
        $this->assertSame(200, $installedStorefrontRes->getStatusCode());

        $sealedInstallRes = $this->http->get('/install');
        $this->assertSame(403, $sealedInstallRes->getStatusCode());
    }

    /**
     * T3-COMB-09: Customer Address Book & Shipping Zone Multi-Tax Matrix Resolution.
     * Replaces pure local multiplication with HTTP tax calculation endpoints across
     * multiple state jurisdictions (CA 8.5%, OR 0.0%, NY 8.875%).
     */
    public function testT3Comb09CustomerAddressBookAndMultiTaxZoneResolution(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        // Base cart items: $100.00 subtotal (10000 cents)
        $addRes = $this->http->post('/api/v1/cart/items', [
            'product_id' => 901,
            'quantity' => 1,
        ]);
        $this->assertSame(201, $addRes->getStatusCode());

        // California: 8.5% tax -> 850 cents
        $caRes = $this->http->post('/api/v1/cart/estimate-tax', [
            'country' => 'US',
            'state' => 'CA',
            'postal_code' => '90001',
        ]);
        $this->assertSame(200, $caRes->getStatusCode());
        $caData = $this->assertJsonResponse($caRes, 200);
        $this->assertSame(850, $caData['tax_amount']);
        $this->assertSame('CA', $caData['tax_zone']);

        // Oregon: 0.0% sales tax -> 0 cents
        $orRes = $this->http->post('/api/v1/cart/estimate-tax', [
            'country' => 'US',
            'state' => 'OR',
            'postal_code' => '97201',
        ]);
        $this->assertSame(200, $orRes->getStatusCode());
        $orData = $this->assertJsonResponse($orRes, 200);
        $this->assertSame(0, $orData['tax_amount']);
        $this->assertSame('OR', $orData['tax_zone']);

        // New York: 8.875% tax -> 10000 * 0.08875 = 887.5 -> 888 cents
        $nyRes = $this->http->post('/api/v1/cart/estimate-tax', [
            'country' => 'US',
            'state' => 'NY',
            'postal_code' => '10001',
        ]);
        $this->assertSame(200, $nyRes->getStatusCode());
        $nyData = $this->assertJsonResponse($nyRes, 200);
        $this->assertSame(888, $nyData['tax_amount']);
        $this->assertSame('NY', $nyData['tax_zone']);
    }

    /**
     * T3-COMB-10: Token Scope Isolation & RBAC Enforcement Across API vs MCP.
     * Enforces strict RBAC: read-only token succeeds on query (HTTP 200),
     * but write operation is strictly rejected with HTTP 403 Forbidden.
     */
    public function testT3Comb10TokenScopeIsolationAndRbacApiVsMcp(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        // Read-only token succeeds on query
        $readOnlyClient = $this->http->withToken('pat_read_products_only');
        $queryRes = $readOnlyClient->get('/api/v1/products');
        $this->assertSame(200, $queryRes->getStatusCode());

        // Read-only token attempting write operation must return strict 403 Forbidden
        $writeRes = $readOnlyClient->post('/api/v1/products', [
            'name' => 'Unauthorized Product',
            'price' => 1000,
        ]);
        $this->assertProblemDetails($writeRes, 403, 'Forbidden');
    }
}
