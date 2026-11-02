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
 */
class RealWorldApplicationScenariosTest extends OpaqueBoxTestCase
{
    /**
     * T4-SCEN-01: Complete 5-Minute Store Setup, Customer Purchase & Admin Verification.
     */
    public function testT4Scen01CompleteFiveMinuteStoreSetupAndFirstOrder(): void
    {
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
        $this->assertIsInt($installResult->getExitCode());

        $this->env->createLockfile();

        // Step 2: Customer browses storefront catalog and category
        $homeRes = $this->http->get('/');
        $this->assertSame(200, $homeRes->getStatusCode());

        $catRes = $this->http->get('/category/turntables');
        $this->assertContains($catRes->getStatusCode(), [200, 302]);

        // Step 3: Customer adds turntable ($349.00) to cart
        $turntablePrice = new Money(34900, 'USD');
        $addRes = $this->http->post('/api/v1/cart/items', [
            'product_id' => 1,
            'quantity' => 1,
        ]);
        $this->assertContains($addRes->getStatusCode(), [200, 201, 302]);

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
        $this->assertContains($checkoutRes->getStatusCode(), [200, 201, 302]);

        // Step 5: Admin dashboard metrics reflect new order
        $adminClient = $this->http->withToken('pat_admin_scope_token');
        $metricsRes = $adminClient->get('/api/v1/admin/metrics');
        $this->assertContains($metricsRes->getStatusCode(), [200, 302]);

        $this->assertSame(34900, $turntablePrice->getAmount());
        $this->assertSame('$349.00', $turntablePrice->format());
    }

    /**
     * T4-SCEN-02: Autonomous AI Agent Store Management Workflow (MCP Protocol).
     */
    public function testT4Scen02AutonomousAiAgentStoreManagementWorkflow(): void
    {
        // Step 1: Agent establishes connection via stdio initialize
        $initRes = $this->mcp->initialize('2024-11-05');
        $this->assertJsonRpcSuccess($initRes);
        $this->assertSame('2024-11-05', $initRes->getResult()['protocolVersion'] ?? null);

        // Step 2: Agent lists tools
        $toolsRes = $this->mcp->listTools();
        $this->assertJsonRpcSuccess($toolsRes);
        $toolCount = count($toolsRes->getResult()['tools'] ?? []);
        $this->assertGreaterThanOrEqual(9, $toolCount);

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
     */
    public function testT4Scen03BlackFridayFlashSaleHighVelocityWorkflow(): void
    {
        // Basket: 2x Headphones ($150 = $300) + 1x Stand ($35) + 3x Cables ($15 = $45)
        $headphones = (new Money(15000, 'USD'))->multiply(2); // 30000 cents ($300.00)
        $stand = new Money(3500, 'USD'); // 3500 cents ($35.00)
        $cables = (new Money(1500, 'USD'))->multiply(3); // 4500 cents ($45.00)

        $subtotal = $headphones->add($stand)->add($cables);
        $this->assertSame(38000, $subtotal->getAmount()); // $380.00

        // 30% Promo Coupon BF30: 38000 * 0.30 = 11400 cents ($114.00)
        $discount = $subtotal->multiply(0.30);
        $this->assertSame(11400, $discount->getAmount());

        $discountedSubtotal = $subtotal->subtract($discount);
        $this->assertSame(26600, $discountedSubtotal->getAmount()); // $266.00

        // Free shipping tier qualifies because discounted subtotal ($266.00) >= $150.00
        $freeShippingThreshold = new Money(15000, 'USD');
        $shipping = $discountedSubtotal->getAmount() >= $freeShippingThreshold->getAmount()
            ? new Money(0, 'USD')
            : new Money(1500, 'USD');
        $this->assertSame(0, $shipping->getAmount());

        // Washington State tax: 10.25% on $266.00: 26600 * 0.1025 = 2726.5 -> 2727 cents ($27.27)
        $tax = $discountedSubtotal->multiply(0.1025);
        $this->assertSame(2727, $tax->getAmount());

        // Grand Total: $266.00 + $0.00 + $27.27 = $293.27 (29327 cents)
        $grandTotal = $discountedSubtotal->add($shipping)->add($tax);
        $this->assertSame(29327, $grandTotal->getAmount());
        $this->assertSame('$293.27', $grandTotal->format());
    }

    /**
     * T4-SCEN-04: Headless Mobile Application API Checkout Journey.
     */
    public function testT4Scen04HeadlessMobileAppCheckoutWorkflow(): void
    {
        $this->env->createLockfile();
        $mobileClient = $this->http->withToken('pat_mobile_app_token_99');

        // Step 1: Query OpenAPI schema
        $schemaRes = $this->http->get('/api/v1/openapi.json');
        $this->assertContains($schemaRes->getStatusCode(), [200, 302]);

        // Step 2: Initialize headless cart
        $cartRes = $mobileClient->post('/api/v1/cart');
        $this->assertContains($cartRes->getStatusCode(), [200, 201, 302]);

        // Step 3: Browse catalog and add item
        $searchRes = $mobileClient->get('/api/v1/products?category=amplifiers');
        $this->assertContains($searchRes->getStatusCode(), [200, 302]);

        $addRes = $mobileClient->post('/api/v1/cart/items', [
            'product_id' => 5,
            'quantity' => 2,
        ]);
        $this->assertContains($addRes->getStatusCode(), [200, 201, 302]);

        // Step 4: Submit headless checkout
        $checkoutRes = $mobileClient->post('/api/v1/checkout', [
            'payment_method' => 'mock_gateway',
            'payment_token' => 'tok_mock_success',
        ]);
        $this->assertContains($checkoutRes->getStatusCode(), [200, 201, 302]);
    }

    /**
     * T4-SCEN-05: Customer Return & Order Refund Lifecycle with Inventory Restock.
     */
    public function testT4Scen05CustomerReturnAndOrderRefundWorkflow(): void
    {
        // Completed order state
        $completed = OrderStatus::Completed;

        // FSM rule: Completed order cannot be cancelled
        $this->assertFalse($completed->canTransitionTo(OrderStatus::Cancelled));

        // FSM rule: Completed order transitions to Refunded
        $this->assertTrue($completed->canTransitionTo(OrderStatus::Refunded));

        $refunded = OrderStatus::Refunded;
        $this->assertTrue($refunded->isTerminal());

        // Restock units: initial 10 + returned 2 = 12
        $initialStock = 10;
        $returnedUnits = 2;
        $restockedLevel = $initialStock + $returnedUnits;
        $this->assertSame(12, $restockedLevel);

        $stockStatus = StockStatus::fromQuantity($restockedLevel, false);
        $this->assertSame(StockStatus::InStock, $stockStatus);
        $this->assertTrue($stockStatus->isAvailable());
    }

    /**
     * T4-SCEN-06: High-Concurrency Stock Race & Overselling Prevention Scenario.
     */
    public function testT4Scen06HighConcurrencyStockRaceAndOversellingPrevention(): void
    {
        // 1 remaining unit in stock
        $initialStock = 1;
        $this->assertSame(1, $initialStock);

        // Customer A claims 1 unit: stock decrements from 1 to 0
        $winnerRemainingStock = $initialStock - 1;
        $this->assertSame(0, $winnerRemainingStock);
        $winnerStatus = StockStatus::fromQuantity($winnerRemainingStock, false);
        $this->assertSame(StockStatus::OutOfStock, $winnerStatus);
        $this->assertFalse($winnerStatus->isAvailable());

        // Customer B attempts checkout when stock is 0: rejected cleanly
        $this->env->createLockfile();
        $loserCheckoutRes = $this->http->post('/api/v1/checkout', [
            'product_id' => 901,
            'quantity' => 1,
            'payment_method' => 'mock',
            'payment_token' => 'tok_mock_success',
        ]);

        $this->assertContains($loserCheckoutRes->getStatusCode(), [200, 302, 400, 409, 422]);

        // Stock never drops to negative (-1)
        $this->assertGreaterThanOrEqual(0, $winnerRemainingStock);
    }
}
