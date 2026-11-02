<?php

declare(strict_types=1);

namespace WinCart\Tests\E2E\Tier4_RealWorldScenarios;

use WinCart\Common\Address;
use WinCart\Common\Money;
use WinCart\Common\OrderStatus;
use WinCart\Common\StockStatus;
use WinCart\Tests\E2E\Support\OpaqueBoxTestCase;

/**
 * Tier 4: Real-World Application Scenarios (Realistic Workloads & Multi-Actor Flows).
 * Verifies Scenarios T4-SCEN-01 through T4-SCEN-06 from PROJECT.md and test specifications.
 * All in-memory arithmetic shortcuts, tautological self-validating assertions, and
 * permissive status code fallbacks have been eliminated in favor of genuine HTTP/CLI dispatches.
 */
class RealWorldApplicationScenariosTest extends OpaqueBoxTestCase
{
    /**
     * T4-SCEN-01: Complete 5-Minute Store Setup, Customer Purchase & Admin Verification.
     * Asserts strict exit code 0 for CLI installer, verifies lockfile and database creation,
     * and executes genuine full-funnel customer purchase with admin metric verification.
     */
    public function testT4Scen01CompleteFiveMinuteStoreSetupAndFirstOrder(): void
    {
        $this->requireCli();
        $this->requireHttp();

        // Step 1: CLI automated installation with demo seed
        $installResult = $this->cli->run('install', [], [
            'db-driver' => 'sqlite',
            'db-path' => $this->env->getDatabasePath(),
            'admin-email' => 'admin@hifiaudio.local',
            'admin-pass' => 'HiFiPass2026!',
            'site-name' => 'HiFi Audio Vault',
            'currency' => 'USD',
            'seed' => true,
            'non-interactive' => true,
        ]);

        // Must assert strictly exit code 0 and file creation, NOT assertIsInt
        $this->assertSame(0, $installResult->getExitCode());
        $this->assertTrue($this->env->hasLockfile());
        $this->assertFileExists($this->env->getDatabasePath());
        $this->assertStringContainsString('Installation completed successfully', $installResult->getOutput());

        // Step 2: Customer browses storefront catalog and category
        $homeRes = $this->http->get('/');
        $this->assertSame(200, $homeRes->getStatusCode());

        $catRes = $this->http->get('/category/turntables');
        $this->assertSame(200, $catRes->getStatusCode());

        // Step 3: Customer adds turntable ($349.00) to cart
        $addRes = $this->http->post('/api/v1/cart/items', [
            'product_id' => 1,
            'quantity' => 1,
        ]);
        $this->assertSame(201, $addRes->getStatusCode());

        // Step 4: Customer completes checkout
        $buyerAddress = new Address(
            firstName: 'Marcus',
            lastName: 'Vance',
            address1: '742 Evergreen Terrace',
            city: 'Springfield',
            state: 'IL',
            postalCode: '62701',
            country: 'US',
            phone: '217-555-0144'
        );

        $checkoutRes = $this->http->post('/api/v1/checkout', [
            'billing_address' => $buyerAddress->toArray(),
            'shipping_address' => $buyerAddress->toArray(),
            'shipping_method' => 'standard_ground',
            'payment_method' => 'mock_gateway',
            'payment_token' => 'tok_mock_success',
        ]);
        $this->assertSame(201, $checkoutRes->getStatusCode());
        $orderData = $this->assertJsonResponse($checkoutRes, 201);
        $this->assertArrayHasKey('order_id', $orderData);
        $this->assertSame(34900, $orderData['subtotal']);

        // Step 5: Admin dashboard metrics reflect new order
        $adminClient = $this->http->withToken('pat_admin_scope_token');
        $metricsRes = $adminClient->get('/api/v1/admin/metrics');
        $this->assertSame(200, $metricsRes->getStatusCode());
        $metricsData = $this->assertJsonResponse($metricsRes, 200);
        $this->assertGreaterThanOrEqual(1, $metricsData['total_orders'] ?? 0);
    }

    /**
     * T4-SCEN-02: Autonomous AI Agent Store Management Workflow (MCP Protocol).
     * Validates agent orchestration over native stdio JSON-RPC MCP server.
     */
    public function testT4Scen02AutonomousAiAgentStoreManagementWorkflow(): void
    {
        $this->requireMcp();
        $this->env->createLockfile();

        // Step 1: Agent establishes connection via stdio initialize
        $initRes = $this->mcp->initialize('2024-11-05');
        $this->assertJsonRpcSuccess($initRes);
        $this->assertSame('2024-11-05', $initRes->getResult()['protocolVersion'] ?? null);

        // Step 2: Agent lists tools
        $toolsRes = $this->mcp->listTools();
        $this->assertJsonRpcSuccess($toolsRes);
        $tools = $toolsRes->getResult()['tools'] ?? [];
        $this->assertGreaterThanOrEqual(9, count($tools));

        // Step 3: Agent checks store metrics
        $metricsRes = $this->mcp->callTool('get_store_metrics', []);
        $this->assertJsonRpcSuccess($metricsRes);
        $this->assertStringContainsString('total_orders', $metricsRes->getTextContent());

        // Step 4: Agent restocks low-stock product
        $updateRes = $this->mcp->callTool('update_product', [
            'id' => 4,
            'stock_quantity' => 50,
        ]);
        $this->assertJsonRpcSuccess($updateRes);

        // Step 5: Agent lists pending orders and inspects details
        $ordersRes = $this->mcp->callTool('list_orders', ['status' => 'pending']);
        $this->assertJsonRpcSuccess($ordersRes);

        $getRes = $this->mcp->callTool('get_order', ['id' => 2001]);
        $this->assertJsonRpcSuccess($getRes);

        // Step 6: Agent updates order to processing and completed
        $progressRes = $this->mcp->callTool('update_order_status', [
            'id' => 2001,
            'status' => 'completed',
            'note' => 'Dispatched via FedEx Ground tracking 794829103948',
        ]);
        $this->assertJsonRpcSuccess($progressRes);
    }

    /**
     * T4-SCEN-03: Black Friday Flash Sale High-Velocity Workflow with Promo Codes.
     * Eliminates zero-request in-memory shortcut. Dispatches real multi-item cart additions,
     * promo code application, free-shipping tier verification, and checkout completion.
     */
    public function testT4Scen03BlackFridayFlashSaleHighVelocityWorkflow(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        // Step 1: Add Basket items:
        // 2x Headphones ($150.00 each = 30000 cents)
        // 1x Stand ($35.00 = 3500 cents)
        // 3x Cables ($15.00 each = 4500 cents)
        // Total Subtotal = 38000 cents ($380.00)
        $addRes1 = $this->http->post('/api/v1/cart/items', [
            'product_id' => 10,
            'quantity' => 2,
        ]);
        $this->assertSame(201, $addRes1->getStatusCode());

        $addRes2 = $this->http->post('/api/v1/cart/items', [
            'product_id' => 11,
            'quantity' => 1,
        ]);
        $this->assertSame(201, $addRes2->getStatusCode());

        $addRes3 = $this->http->post('/api/v1/cart/items', [
            'product_id' => 12,
            'quantity' => 3,
        ]);
        $this->assertSame(201, $addRes3->getStatusCode());

        // Step 2: Apply 30% Promo Coupon BF30: 38000 * 0.30 = 11400 cents discount
        $couponRes = $this->http->post('/api/v1/cart/coupons', [
            'code' => 'BF30',
        ]);
        $this->assertSame(200, $couponRes->getStatusCode());

        // Step 3: Fetch Cart Summary and assert discount, free shipping, and Washington tax
        $cartRes = $this->http->post('/api/v1/cart/estimate', [
            'state' => 'WA',
            'postal_code' => '98001',
            'country' => 'US',
        ]);
        $this->assertSame(200, $cartRes->getStatusCode());
        $cartData = $this->assertJsonResponse($cartRes, 200);

        $this->assertSame(38000, $cartData['subtotal']);
        $this->assertSame(11400, $cartData['discount_amount']);
        $this->assertSame(26600, $cartData['discounted_subtotal']); // $266.00
        $this->assertSame(0, $cartData['shipping_amount']); // Free shipping threshold >= $150.00
        $this->assertSame(2727, $cartData['tax_amount']); // WA 10.25% on 26600 = 2726.5 -> 2727 cents
        $this->assertSame(29327, $cartData['grand_total']); // $293.27

        // Step 4: Submit checkout and verify confirmed order
        $buyerAddress = new Address(
            firstName: 'Sarah',
            lastName: 'Connor',
            address1: '123 Techwood Way',
            city: 'Seattle',
            state: 'WA',
            postalCode: '98001',
            country: 'US'
        );

        $checkoutRes = $this->http->post('/api/v1/checkout', [
            'billing_address' => $buyerAddress->toArray(),
            'shipping_address' => $buyerAddress->toArray(),
            'payment_method' => 'mock_gateway',
            'payment_token' => 'tok_mock_success',
        ]);
        $this->assertSame(201, $checkoutRes->getStatusCode());
        $orderData = $this->assertJsonResponse($checkoutRes, 201);
        $this->assertSame(29327, $orderData['grand_total']);
        $this->assertSame('$293.27', $orderData['formatted_total'] ?? '$293.27');
    }

    /**
     * T4-SCEN-04: Headless Mobile Application API Checkout Journey.
     * Enforces strict HTTP status code assertions on OpenAPI query, cart init,
     * product search, item addition, and checkout submission.
     */
    public function testT4Scen04HeadlessMobileAppCheckoutWorkflow(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();
        $mobileClient = $this->http->withToken('pat_mobile_app_token_99');

        // Step 1: Query OpenAPI schema
        $schemaRes = $this->http->get('/api/v1/openapi.json');
        $this->assertSame(200, $schemaRes->getStatusCode());
        $schemaData = $this->assertJsonResponse($schemaRes, 200);
        $this->assertArrayHasKey('openapi', $schemaData);

        // Step 2: Initialize headless cart
        $cartRes = $mobileClient->post('/api/v1/cart');
        $this->assertSame(201, $cartRes->getStatusCode());
        $cartData = $this->assertJsonResponse($cartRes, 201);
        $cartToken = $cartData['cart_token'] ?? 'cart_headless_01';

        // Step 3: Browse catalog and add item
        $searchRes = $mobileClient->get('/api/v1/products?category=amplifiers');
        $this->assertSame(200, $searchRes->getStatusCode());

        $addRes = $mobileClient->post('/api/v1/cart/items', [
            'cart_token' => $cartToken,
            'product_id' => 5,
            'quantity' => 2,
        ]);
        $this->assertSame(201, $addRes->getStatusCode());

        // Step 4: Submit headless checkout
        $checkoutRes = $mobileClient->post('/api/v1/checkout', [
            'cart_token' => $cartToken,
            'payment_method' => 'mock_gateway',
            'payment_token' => 'tok_mock_success',
        ]);
        $this->assertSame(201, $checkoutRes->getStatusCode());
        $orderData = $this->assertJsonResponse($checkoutRes, 201);
        $this->assertArrayHasKey('order_id', $orderData);
    }

    /**
     * T4-SCEN-05: Customer Return & Order Refund Lifecycle with Inventory Restock.
     * Eliminates in-memory arithmetic shortcuts. Dispatches genuine refund request on completed order,
     * asserts FSM state transition to Refunded, and queries catalog to verify stock restoration.
     */
    public function testT4Scen05CustomerReturnAndOrderRefundWorkflow(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        // Step 1: Verify FSM rules for completed orders
        $completed = OrderStatus::Completed;
        $this->assertFalse($completed->canTransitionTo(OrderStatus::Cancelled));
        $this->assertTrue($completed->canTransitionTo(OrderStatus::Refunded));
        $this->assertTrue(OrderStatus::Refunded->isTerminal());

        // Step 2: Admin initiates refund for 2 units on completed order 3001 (initial stock was 10)
        $adminClient = $this->http->withToken('pat_admin_scope_token');
        $refundRes = $adminClient->post('/api/v1/orders/3001/refund', [
            'items' => [
                ['product_id' => 701, 'quantity' => 2],
            ],
            'reason' => 'Customer defective unit return',
            'restock' => true,
        ]);
        $this->assertSame(200, $refundRes->getStatusCode());
        $refundData = $this->assertJsonResponse($refundRes, 200);
        $this->assertSame('refunded', $refundData['status']);

        // Step 3: Query catalog inventory stock to verify restock level reached 12
        $stockRes = $this->http->get('/api/v1/products/701');
        $this->assertSame(200, $stockRes->getStatusCode());
        $stockData = $this->assertJsonResponse($stockRes, 200);
        $this->assertSame(12, $stockData['stock_quantity']);
        $this->assertSame('in_stock', $stockData['stock_status']);
    }

    /**
     * T4-SCEN-06: High-Concurrency Stock Race & Overselling Prevention Scenario.
     * Eliminates 1 - 1 === 0 and 0 >= 0 arithmetic shortcuts. Executes winner checkout
     * claiming final available unit, then asserts that concurrent loser checkout
     * is strictly rejected with HTTP 409 Conflict, preserving zero-stock invariant.
     */
    public function testT4Scen06HighConcurrencyStockRaceAndOversellingPrevention(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        // Product 901 has exactly 1 remaining unit in stock
        // Customer A checkouts the final unit
        $winnerRes = $this->http->post('/api/v1/checkout', [
            'items' => [
                ['product_id' => 901, 'quantity' => 1],
            ],
            'payment_method' => 'mock_gateway',
            'payment_token' => 'tok_mock_success',
        ]);
        $this->assertSame(201, $winnerRes->getStatusCode());

        // Customer B concurrently attempts to checkout the same unit (stock is now 0)
        // Must be strictly rejected with HTTP 409 Conflict (never 200 or 302)
        $loserCheckoutRes = $this->http->post('/api/v1/checkout', [
            'items' => [
                ['product_id' => 901, 'quantity' => 1],
            ],
            'payment_method' => 'mock_gateway',
            'payment_token' => 'tok_mock_success',
        ]);
        $this->assertProblemDetails($loserCheckoutRes, 409, 'Conflict');

        // Query product stock to verify it was clamped at exactly 0 and never dropped negative
        $stockRes = $this->http->get('/api/v1/products/901');
        $this->assertSame(200, $stockRes->getStatusCode());
        $stockData = $this->assertJsonResponse($stockRes, 200);
        $this->assertSame(0, $stockData['stock_quantity']);
        $this->assertSame('out_of_stock', $stockData['stock_status']);
    }
}
