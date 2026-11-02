<?php

declare(strict_types=1);

namespace WinCart\Cart\Services;

use WinCart\Cart\Models\Cart;
use WinCart\Cart\Models\CartItem;
use WinCart\Cart\Models\CartTotals;

interface CartManagerInterface
{
    public function getOrCreateCart(?string $token = null, ?int $customerId = null): Cart;

    public function getCartByToken(string $token): ?Cart;

    public function getCartByCustomerId(int $customerId): ?Cart;

    public function addItem(Cart $cart, int $productId, int $quantity = 1, ?int $variationId = null): CartItem;

    public function updateItemQuantity(Cart $cart, int $itemId, int $quantity): CartItem;

    public function removeItem(Cart $cart, int $itemId): bool;

    public function applyCoupon(Cart $cart, string $couponCode): Cart;

    public function removeCoupon(Cart $cart): Cart;

    public function clearCart(Cart $cart): bool;

    public function calculateTotals(Cart $cart, ?string $state = null, ?string $country = 'US'): CartTotals;

    /**
     * Auto-merge guest session cart into authenticated customer cart.
     */
    public function mergeGuestCartIntoCustomerCart(string $guestToken, int $customerId): Cart;
}
