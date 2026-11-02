<?php

declare(strict_types=1);

namespace WinCart\Tests\E2E\Tier2_BoundaryCornerCases;

use InvalidArgumentException;
use WinCart\Common\Money;
use WinCart\Common\StockStatus;
use WinCart\Tests\E2E\Support\OpaqueBoxTestCase;

/**
 * Tier 2: Boundary & Corner Cases — Stock Constraints & Value Limits.
 * Verifies edge cases #6-#10 from PROJECT.md and test specifications.
 */
class BoundaryLimitsTest extends OpaqueBoxTestCase
{
    /**
     * Test 1: Rejection when requested cart quantity exceeds available stock.
     */
    public function testBoundaryProductQuantityExceedingStock(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        $response = $this->http->post('/api/v1/cart/items', [
            'product_id' => 1,
            'quantity' => 999999,
        ]);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertProblemDetails($response, 409, 'Conflict');
    }

    /**
     * Test 2: Rejection of zero or negative item quantity additions.
     */
    public function testBoundaryZeroAndNegativeQuantityAdditions(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        $zeroRes = $this->http->post('/api/v1/cart/items', [
            'product_id' => 1,
            'quantity' => 0,
        ]);
        $this->assertSame(422, $zeroRes->getStatusCode());
        $this->assertProblemDetails($zeroRes, 422, 'Unprocessable Entity');

        $negRes = $this->http->post('/api/v1/cart/items', [
            'product_id' => 1,
            'quantity' => -5,
        ]);
        $this->assertSame(422, $negRes->getStatusCode());
        $this->assertProblemDetails($negRes, 422, 'Unprocessable Entity');
    }

    /**
     * Test 3: Strict rejection of negative prices via Money value object.
     */
    public function testBoundaryNegativePriceRejection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Money amount cannot be negative');

        new Money(-1500, 'USD');
    }

    /**
     * Test 4: Sub-cent float price half-up deterministic rounding.
     */
    public function testBoundarySubCentFloatingPointPriceRounding(): void
    {
        $roundUp = Money::fromFloat(19.995, 'USD');
        $this->assertSame(2000, $roundUp->getAmount());
        $this->assertSame('$20.00', $roundUp->format());

        $roundDown = Money::fromFloat(19.994, 'USD');
        $this->assertSame(1999, $roundDown->getAmount());
        $this->assertSame('$19.99', $roundDown->format());
    }

    /**
     * Test 5: Exact inventory stock drain to zero and status transition to OutOfStock.
     */
    public function testBoundaryStockPurchaseLimitExactDrain(): void
    {
        $initialStock = StockStatus::fromQuantity(1, false);
        $this->assertSame(StockStatus::InStock, $initialStock);
        $this->assertTrue($initialStock->isAvailable());

        // Drain 1 unit: stock becomes 0
        $drainedStock = StockStatus::fromQuantity(0, false);
        $this->assertSame(StockStatus::OutOfStock, $drainedStock);
        $this->assertFalse($drainedStock->isAvailable());
    }
}
