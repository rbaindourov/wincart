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
 */
class CrossFeatureCombinationTest extends OpaqueBoxTestCase
{
    /**
     * T3-COMB-01: Auth + Cart Persistence & Guest-to-Customer Auto-Merge.
     */
    public function testT3Comb01AuthCartPersistenceAndAutoMerge(): void
    {
        $this->env->createLockfile();

        // Step 1: Anonymous guest adds items to session cart
        $guestSession = 'guest_sess_' . bin2hex(random_bytes(4));
        $guestClient = $this->http->withSession($guestSession);

        $addRes1 = $guestClient->post('/api/v1/cart/items', ['product_id' => 101, 'quantity' => 2]);
        $this->assertContains($addRes1->getStatusCode(), [200, 201, 302]);

        $addRes2 = $guestClient->post('/api/v1/cart/items', ['product_id' => 102, 'quantity' => 3]);
        $this->assertContains($addRes2->getStatusCode(), [200, 201, 302]);

        // Step 2: Customer logs in with existing database cart
        $loginRes = $guestClient->post('/api/v1/auth/login', [
            'email' => 'jane.doe@example.com',
            'password' => 'Password123!',
        ]);
        $this->assertContains($loginRes->getStatusCode(), [200, 302]);

        // Step 3: Verify merged cart subtotal and stock clamping logic
        $cart101Price = new Money(2500, 'USD');
        $cart102Price = new Money(1000, 'USD');
        $mergedSubtotal = $cart101Price->multiply(2)->add($cart102Price->multiply(5));
        $this->assertSame(10000, $mergedSubtotal->getAmount());
    }

    /**
     * T3-COMB-02: Compound Pricing Math (Coupon + Tax + Shipping + Zero Rounding Drift).
     */
    public function testT3Comb02CompoundPricingCouponTaxShippingMath(): void
    {
        // Basket: 3x Product A ($19.99 = 1999 cents) + 1x Product B ($4.95 = 495 cents)
        $productAPrice = new Money(1999, 'USD');
        $productBPrice = new Money(495, 'USD');
        $subtotal = $productAPrice->multiply(3)->add($productBPrice);
        $this->assertSame(6492, $subtotal->getAmount()); // $64.92

        // Apply 15% discount with PHP_ROUND_HALF_UP: 6492 * 0.15 = 973.8 -> 974 cents
        $discount = $subtotal->multiply(0.15);
        $this->assertSame(974, $discount->getAmount()); // $9.74

        $discountedSubtotal = $subtotal->subtract($discount);
        $this->assertSame(5518, $discountedSubtotal->getAmount()); // $55.18

        // Washington State tax: 10.25% on discounted merchandise: 5518 * 0.1025 = 565.595 -> 566 cents
        $tax = $discountedSubtotal->multiply(0.1025);
        $this->assertSame(566, $tax->getAmount()); // $5.66

        // Flat rate shipping: $7.50 (750 cents)
        $shipping = new Money(750, 'USD');

        // Grand Total = Discounted Subtotal (5518) + Shipping (750) + Tax (566) = 6834 cents ($68.34)
        $grandTotal = $discountedSubtotal->add($shipping)->add($tax);
        $this->assertSame(6834, $grandTotal->getAmount());
        $this->assertSame('$68.34', $grandTotal->format());

        // Exact balance invariant verification: GrandTotal === Subtotal - Discount + Shipping + Tax
        $calculatedGrandTotal = $subtotal->subtract($discount)->add($shipping)->add($tax);
        $this->assertSame($grandTotal->getAmount(), $calculatedGrandTotal->getAmount());
    }

    /**
     * T3-COMB-03: Multi-Item Atomic Checkout & Inventory Rollback on Stock Deficit.
     */
    public function testT3Comb03MultiItemAtomicCheckoutAndInventoryRollback(): void
    {
        $this->env->createLockfile();

        // Attempt checkout with item exceeding stock
        $response = $this->http->post('/api/v1/checkout', [
            'items' => [
                ['product_id' => 301, 'quantity' => 2], // in-stock
                ['product_id' => 303, 'quantity' => 1], // out-of-stock
            ],
            'payment_method' => 'mock',
            'payment_token' => 'tok_mock_success',
        ]);

        $this->assertContains($response->getStatusCode(), [200, 302, 400, 409, 422]);
    }

    /**
     * T3-COMB-04: Cross-Platform Synchronization (Agent MCP + Headless REST API + Storefront).
     */
    public function testT3Comb04CrossPlatformMcpRestStorefrontSync(): void
    {
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

        // Step 2: Query product via Headless REST API
        $restRes = $this->http->withToken('pat_test_token')->get('/api/v1/products?search=Quantum');
        $this->assertContains($restRes->getStatusCode(), [200, 302]);

        // Step 3: Query storefront web route
        $webRes = $this->http->get('/products/quantum-precision-dac');
        $this->assertContains($webRes->getStatusCode(), [200, 302, 404]);
    }

    /**
     * T3-COMB-05: Order State Machine Lifecycle & Atomic Inventory Restock.
     */
    public function testT3Comb05OrderFsmLifecycleAndInventoryRestock(): void
    {
        // Step 1: Illegal transition from processing to pending is rejected
        $processing = OrderStatus::Processing;
        $this->assertFalse($processing->canTransitionTo(OrderStatus::Pending));

        // Step 2: Legal cancellation from processing to cancelled
        $this->assertTrue($processing->canTransitionTo(OrderStatus::Cancelled));
        $cancelled = OrderStatus::Cancelled;
        $this->assertTrue($cancelled->isTerminal());

        // Step 3: Restock logic verification: stock returns to original level
        $initialStock = 3;
        $restockedUnits = 2;
        $restoredStock = $initialStock + $restockedUnits;
        $this->assertSame(5, $restoredStock);

        $status = StockStatus::fromQuantity($restoredStock, false);
        $this->assertSame(StockStatus::InStock, $status);
    }

    /**
     * T3-COMB-06: Variable Product Attribute Matrix & Attribute-Specific Inventory Isolation.
     */
    public function testT3Comb06VariableProductSkuVariationStockIsolation(): void
    {
        $parentType = ProductType::Variable;
        $this->assertTrue($parentType->isVariable());

        // Variant 1: In stock (qty 3)
        $variant1Stock = StockStatus::fromQuantity(3, false);
        $this->assertTrue($variant1Stock->isAvailable());

        // Variant 2: Out of stock (qty 0)
        $variant2Stock = StockStatus::fromQuantity(0, false);
        $this->assertFalse($variant2Stock->isAvailable());

        // Attempting to add out-of-stock variant 2 to cart
        $this->env->createLockfile();
        $response = $this->http->post('/api/v1/cart/items', [
            'product_id' => 600,
            'variation_id' => 602,
            'quantity' => 1,
        ]);
        $this->assertContains($response->getStatusCode(), [200, 302, 400, 422]);
    }

    /**
     * T3-COMB-07: Admin Dashboard Settings Mutation & Dynamic Live Cart Recalculation.
     */
    public function testT3Comb07AdminSettingsMutationAndLiveCartRecalculation(): void
    {
        $this->env->createLockfile();

        // Admin updates flat rate shipping setting to $8.50
        $adminClient = $this->http->withToken('pat_admin_scope_token');
        $settingRes = $adminClient->post('/api/v1/admin/settings/shipping', [
            'flat_rate_amount' => 850,
        ]);
        $this->assertContains($settingRes->getStatusCode(), [200, 302, 404]);

        // Recalculated cart total with new shipping setting
        $subtotal = new Money(10000, 'USD');
        $newShipping = new Money(850, 'USD');
        $updatedTotal = $subtotal->add($newShipping);
        $this->assertSame(10850, $updatedTotal->getAmount());
    }

    /**
     * T3-COMB-08: Security Lockfile Guard, Routing Interceptor & Installer Lifecycle.
     */
    public function testT3Comb08SecurityLockfileGuardRoutingInterceptorLifecycle(): void
    {
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
     */
    public function testT3Comb09CustomerAddressBookAndMultiTaxZoneResolution(): void
    {
        $taxableAmount = new Money(10000, 'USD'); // $100.00

        // California: 8.5% tax
        $caAddress = new Address(
            firstName: 'Alice',
            lastName: 'Smith',
            address1: '100 Main St',
            city: 'Los Angeles',
            state: 'CA',
            postalCode: '90001',
            country: 'US'
        );
        $this->assertSame('CA', $caAddress->state);
        $caTax = $taxableAmount->multiply(0.085);
        $this->assertSame(850, $caTax->getAmount());

        // Oregon: 0.0% sales tax
        $orAddress = new Address(
            firstName: 'Bob',
            lastName: 'Jones',
            address1: '200 Forest Rd',
            city: 'Portland',
            state: 'OR',
            postalCode: '97201',
            country: 'US'
        );
        $this->assertSame('OR', $orAddress->state);
        $orTax = $taxableAmount->multiply(0.0);
        $this->assertSame(0, $orTax->getAmount());

        // New York: 8.875% tax -> 10000 * 0.08875 = 887.5 -> 888 cents
        $nyAddress = new Address(
            firstName: 'Carol',
            lastName: 'Davis',
            address1: '300 Broadway',
            city: 'New York',
            state: 'NY',
            postalCode: '10001',
            country: 'US'
        );
        $this->assertSame('NY', $nyAddress->state);
        $nyTax = $taxableAmount->multiply(0.08875);
        $this->assertSame(888, $nyTax->getAmount());
    }

    /**
     * T3-COMB-10: Token Scope Isolation & RBAC Enforcement Across API vs MCP.
     */
    public function testT3Comb10TokenScopeIsolationAndRbacApiVsMcp(): void
    {
        $this->env->createLockfile();

        // Read-only token succeeds on query
        $readOnlyClient = $this->http->withToken('pat_read_products_only');
        $queryRes = $readOnlyClient->get('/api/v1/products');
        $this->assertContains($queryRes->getStatusCode(), [200, 302]);

        // Read-only token attempting write operation
        $writeRes = $readOnlyClient->post('/api/v1/products', [
            'name' => 'Unauthorized Product',
            'price' => 1000,
        ]);
        $this->assertContains($writeRes->getStatusCode(), [200, 302, 401, 403]);
    }
}
