<?php

declare(strict_types=1);

namespace WinCart\Tests\E2E\Tier2_BoundaryCornerCases;

use WinCart\Common\OrderStatus;
use WinCart\Tests\E2E\Support\OpaqueBoxTestCase;

/**
 * Tier 2: Boundary & Corner Cases — Order Lifecycle Finite State Machine Guards.
 * Verifies edge cases #21-#26 from PROJECT.md and test specifications.
 */
class IllegalStateTransitionsTest extends OpaqueBoxTestCase
{
    /**
     * Test 1: Reverting completed order to pending or processing is strictly rejected.
     */
    public function testFsmRevertingCompletedOrderRejected(): void
    {
        $completed = OrderStatus::Completed;
        $this->assertFalse($completed->canTransitionTo(OrderStatus::Pending));
        $this->assertFalse($completed->canTransitionTo(OrderStatus::Processing));
    }

    /**
     * Test 2: Cancelling an already refunded order is rejected (terminal state guard).
     */
    public function testFsmCancellingAlreadyRefundedOrderRejected(): void
    {
        $refunded = OrderStatus::Refunded;
        $this->assertTrue($refunded->isTerminal());
        $this->assertFalse($refunded->canTransitionTo(OrderStatus::Cancelled));
    }

    /**
     * Test 3: Refunding an uncaptured pending order is rejected.
     */
    public function testFsmRefundingUncapturedPendingOrderRejected(): void
    {
        $pending = OrderStatus::Pending;
        $this->assertFalse($pending->canTransitionTo(OrderStatus::Refunded));
        $this->assertTrue($pending->canTransitionTo(OrderStatus::Cancelled));
    }

    /**
     * Test 4: Transitioning a cancelled order to completed is rejected.
     */
    public function testFsmTransitioningCancelledOrderToCompletedRejected(): void
    {
        $cancelled = OrderStatus::Cancelled;
        $this->assertTrue($cancelled->isTerminal());
        $this->assertFalse($cancelled->canTransitionTo(OrderStatus::Completed));
    }

    /**
     * Test 5: Idempotency of terminal state transitions (self-transition evaluation).
     */
    public function testFsmDoubleRefundAndDoubleCancelIdempotency(): void
    {
        $refunded = OrderStatus::Refunded;
        $this->assertTrue($refunded->canTransitionTo(OrderStatus::Refunded));

        $cancelled = OrderStatus::Cancelled;
        $this->assertTrue($cancelled->canTransitionTo(OrderStatus::Cancelled));
    }

    /**
     * Test 6: Skipping fulfillment processing from pending directly to completed is rejected.
     */
    public function testFsmSkippingProcessingFromPendingToCompletedRejected(): void
    {
        $pending = OrderStatus::Pending;
        $this->assertFalse($pending->canTransitionTo(OrderStatus::Completed));
    }
}
