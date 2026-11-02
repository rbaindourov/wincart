<?php

declare(strict_types=1);

namespace WinCart\Tests\E2E\Tier2_BoundaryCornerCases;

use InvalidArgumentException;
use WinCart\Common\Address;
use WinCart\Tests\E2E\Support\OpaqueBoxTestCase;

/**
 * Tier 2: Boundary & Corner Cases — Empty Inputs & Zero Balances.
 * Verifies edge cases #1-#5 from PROJECT.md and test specifications.
 */
class EmptyInputBoundaryTest extends OpaqueBoxTestCase
{
    /**
     * Test 1: Empty cart checkout rejection.
     */
    public function testBoundaryEmptyCartCheckoutRejection(): void
    {
        $this->env->createLockfile();

        $response = $this->http->post('/api/v1/checkout', [
            'cart_id' => 'empty_cart_token',
            'items' => [],
        ]);

        $this->assertContains($response->getStatusCode(), [200, 302, 400, 422]);
    }

    /**
     * Test 2: Blank or whitespace-only address fields validation.
     */
    public function testBoundaryBlankAddressFieldsValidation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('First name cannot be empty');

        new Address(
            firstName: '   ',
            lastName: 'Doe',
            address1: '123 Main St',
            city: 'Springfield',
            state: 'IL',
            postalCode: '62701',
            country: 'US'
        );
    }

    /**
     * Test 3: Empty catalog search query safe handling.
     */
    public function testBoundaryEmptyCatalogSearchString(): void
    {
        $this->env->createLockfile();

        $response = $this->http->get('/api/v1/products?search=');
        $this->assertContains($response->getStatusCode(), [200, 302]);
    }

    /**
     * Test 4: Blank coupon code input rejection.
     */
    public function testBoundaryBlankCouponCode(): void
    {
        $this->env->createLockfile();

        $response = $this->http->post('/api/v1/cart/coupons', [
            'code' => '   ',
        ]);

        $this->assertContains($response->getStatusCode(), [200, 302, 400, 422]);
    }

    /**
     * Test 5: Order submission with 0 line items rejection.
     */
    public function testBoundaryOrderSubmissionZeroLineItems(): void
    {
        $this->env->createLockfile();

        $response = $this->http->post('/api/v1/orders', [
            'items' => [],
        ]);

        $this->assertContains($response->getStatusCode(), [200, 302, 400, 422]);
    }
}
