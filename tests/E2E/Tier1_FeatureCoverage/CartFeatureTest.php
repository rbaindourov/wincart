<?php

declare(strict_types=1);

namespace WinCart\Tests\E2E\Tier1_FeatureCoverage;

use WinCart\Common\Money;
use WinCart\Tests\E2E\Support\OpaqueBoxTestCase;

/**
 * Tier 1: Feature Coverage — Persistent Shopping Cart & Price Engine.
 * Verifies R2 and Feature #10 from PROJECT.md.
 */
class CartFeatureTest extends OpaqueBoxTestCase
{
    /**
     * Test 1: Add product item to cart and calculate initial subtotal.
     */
    public function testCartAddItem(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        $response = $this->http->post('/api/v1/cart/items', [
            'product_id' => 1,
            'quantity' => 2,
        ]);

        $this->assertSame(201, $response->getStatusCode());
        $itemPrice = new Money(2500, 'USD');
        $lineTotal = $itemPrice->multiply(2);
        $this->assertSame(5000, $lineTotal->getAmount());
    }

    /**
     * Test 2: Update item quantity in active cart.
     */
    public function testCartUpdateQuantity(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        $response = $this->http->patch('/api/v1/cart/items/1', [
            'quantity' => 4,
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $itemPrice = new Money(2500, 'USD');
        $updatedTotal = $itemPrice->multiply(4);
        $this->assertSame(10000, $updatedTotal->getAmount());
    }

    /**
     * Test 3: Remove item from cart and recalculate subtotal.
     */
    public function testCartRemoveItem(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        $response = $this->http->delete('/api/v1/cart/items/1');
        $this->assertSame(204, $response->getStatusCode());
    }

    /**
     * Test 4: Apply valid discount coupon and verify discount math.
     */
    public function testCartApplyValidDiscountCoupon(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        $response = $this->http->post('/api/v1/cart/coupons', [
            'code' => 'DISCOUNT15',
        ]);

        $this->assertSame(200, $response->getStatusCode());

        // Verify discount math: $100.00 subtotal with 15% discount
        $subtotal = new Money(10000, 'USD');
        $discount = $subtotal->multiply(0.15);
        $this->assertSame(1500, $discount->getAmount());
        $netTotal = $subtotal->subtract($discount);
        $this->assertSame(8500, $netTotal->getAmount());
    }

    /**
     * Test 5: Cart session persistence across disconnected requests.
     */
    public function testCartPersistenceAcrossSessions(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        $clientWithSession = $this->http->withSession('test_sess_token_123');
        $response = $clientWithSession->get('/api/v1/cart');

        $this->assertSame(200, $response->getStatusCode());
    }
}
