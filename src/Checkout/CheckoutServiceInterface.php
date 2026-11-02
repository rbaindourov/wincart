<?php

declare(strict_types=1);

namespace WinCart\Checkout;

use WinCart\Cart\Models\Cart;

interface CheckoutServiceInterface
{
    /**
     * @param Cart|null $cart Active cart or null if direct items provided
     * @param array<string, mixed> $checkoutData
     * @return Order
     */
    public function submitOrder(?Cart $cart, array $checkoutData): Order;
}
