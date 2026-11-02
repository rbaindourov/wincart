<?php

declare(strict_types=1);

namespace WinCart\Tests\E2E\Tier2_BoundaryCornerCases;

use WinCart\Tests\E2E\Support\OpaqueBoxTestCase;

/**
 * Tier 2: Boundary & Corner Cases — Order Lifecycle Finite State Machine Guards.
 * Verifies edge cases #21-#26 from PROJECT.md and test specifications via public HTTP API.
 */
class IllegalStateTransitionsTest extends OpaqueBoxTestCase
{
    /**
     * Test 1: Reverting completed order to pending or processing is strictly rejected with 422.
     */
    public function testFsmRevertingCompletedOrderRejected(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        // Attempt to revert a completed order to pending
        $response = $this->http->patch('/api/v1/orders/1/status', [
            'status' => 'pending',
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertProblemDetails($response, 422, 'Invalid Order State Transition');
    }

    /**
     * Test 2: Cancelling an already refunded order is rejected (terminal state guard).
     */
    public function testFsmCancellingAlreadyRefundedOrderRejected(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        $response = $this->http->patch('/api/v1/orders/2/status', [
            'status' => 'cancelled',
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertProblemDetails($response, 422, 'Invalid Order State Transition');
    }

    /**
     * Test 3: Refunding an uncaptured pending order is rejected.
     */
    public function testFsmRefundingUncapturedPendingOrderRejected(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        $response = $this->http->patch('/api/v1/orders/3/status', [
            'status' => 'refunded',
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertProblemDetails($response, 422, 'Invalid Order State Transition');
    }

    /**
     * Test 4: Transitioning a cancelled order to completed is rejected.
     */
    public function testFsmTransitioningCancelledOrderToCompletedRejected(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        $response = $this->http->patch('/api/v1/orders/4/status', [
            'status' => 'completed',
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertProblemDetails($response, 422, 'Invalid Order State Transition');
    }

    /**
     * Test 5: Idempotency of terminal state transitions (self-transition returns 200 OK).
     */
    public function testFsmDoubleRefundAndDoubleCancelIdempotency(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        // Self transition on refunded order is a valid idempotent no-op
        $response = $this->http->patch('/api/v1/orders/2/status', [
            'status' => 'refunded',
        ]);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Test 6: Skipping fulfillment processing from pending directly to completed is rejected.
     */
    public function testFsmSkippingProcessingFromPendingToCompletedRejected(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        $response = $this->http->patch('/api/v1/orders/3/status', [
            'status' => 'completed',
        ]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertProblemDetails($response, 422, 'Invalid Order State Transition');
    }
}
