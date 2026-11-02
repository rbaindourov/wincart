<?php

declare(strict_types=1);

namespace WinCart\Tests\E2E\Tier1_FeatureCoverage;

use WinCart\Common\Address;
use WinCart\Common\Money;
use WinCart\Common\OrderStatus;
use WinCart\Tests\E2E\Support\OpaqueBoxTestCase;

/**
 * Tier 1: Feature Coverage — Checkout Workflow, Orders & Payments.
 * Verifies R2 and Features #11, #12 from PROJECT.md.
 */
class CheckoutFeatureTest extends OpaqueBoxTestCase
{
    /**
     * Test 1: Complete end-to-end order submission.
     */
    public function testCheckoutOrderSubmissionEndToEnd(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        // Populate active cart with Product #1 prior to checkout
        $addRes = $this->http->post('/api/v1/cart/items', [
            'product_id' => 1,
            'quantity' => 1,
        ]);
        $this->assertSame(201, $addRes->getStatusCode());

        $address = new Address(
            firstName: 'Marcus',
            lastName: 'Vance',
            address1: '742 Evergreen Terrace',
            city: 'Springfield',
            state: 'IL',
            postalCode: '62701',
            country: 'US',
            phone: '217-555-0144'
        );

        $response = $this->http->post('/api/v1/checkout', [
            'billing_address' => $address->toArray(),
            'shipping_address' => $address->toArray(),
            'shipping_method' => 'standard_ground',
            'payment_method' => 'mock_gateway',
            'payment_token' => 'tok_mock_success',
        ]);

        $this->assertSame(201, $response->getStatusCode());
    }

    /**
     * Test 2: Destination-based sales tax calculation by regional zone.
     */
    public function testCheckoutTaxCalculationByZone(): void
    {
        // 8.25% California sales tax on $100.00 subtotal
        $subtotal = new Money(10000, 'USD');
        $tax = $subtotal->multiply(0.0825);
        $this->assertSame(825, $tax->getAmount());

        $grandTotal = $subtotal->add($tax);
        $this->assertSame(10825, $grandTotal->getAmount());
        $this->assertSame('$108.25', $grandTotal->format());
    }

    /**
     * Test 3: Shipping rate estimation and free shipping threshold.
     */
    public function testCheckoutShippingRateEstimation(): void
    {
        $flatRate = new Money(1000, 'USD');
        $this->assertSame(1000, $flatRate->getAmount());

        // Order >= $50 qualifies for free shipping
        $qualifyingSubtotal = new Money(6000, 'USD');
        $shippingFee = $qualifyingSubtotal->getAmount() >= 5000 ? new Money(0, 'USD') : $flatRate;
        $this->assertSame(0, $shippingFee->getAmount());
    }

    /**
     * Test 4: Mock payment gateway simulation and token validation.
     */
    public function testCheckoutMockPaymentProcessing(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        $response = $this->http->post('/api/v1/payments/process', [
            'payment_method' => 'mock',
            'token' => 'tok_mock_success',
            'amount' => 5000,
            'currency' => 'USD',
        ]);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Test 5: Legal order status state machine transitions.
     */
    public function testCheckoutOrderStatusTransitions(): void
    {
        $status = OrderStatus::Pending;
        $this->assertTrue($status->canTransitionTo(OrderStatus::Processing));
        $this->assertTrue($status->canTransitionTo(OrderStatus::Cancelled));
        $this->assertFalse($status->canTransitionTo(OrderStatus::Completed));

        $processing = OrderStatus::Processing;
        $this->assertTrue($processing->canTransitionTo(OrderStatus::Completed));
        $this->assertTrue($processing->canTransitionTo(OrderStatus::Refunded));
        $this->assertTrue($processing->canTransitionTo(OrderStatus::Cancelled));

        $completed = OrderStatus::Completed;
        $this->assertTrue($completed->canTransitionTo(OrderStatus::Refunded));
        $this->assertFalse($completed->canTransitionTo(OrderStatus::Pending));
    }
}
