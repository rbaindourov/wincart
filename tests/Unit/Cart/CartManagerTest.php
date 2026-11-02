<?php

declare(strict_types=1);

namespace WinCart\Tests\Unit\Cart;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use WinCart\Cart\Exceptions\InvalidCouponException;
use WinCart\Cart\Pricing\ShippingCalculator;
use WinCart\Cart\Pricing\TaxEngine;
use WinCart\Cart\Services\PdoCartManager;
use WinCart\Catalog\Exceptions\InsufficientStockException;
use WinCart\Catalog\Models\Product;
use WinCart\Catalog\Repositories\PdoProductRepository;
use WinCart\Common\Money;
use WinCart\Common\ProductType;
use WinCart\Common\StockStatus;
use WinCart\Database\ConnectionFactory;
use WinCart\Database\MigrationRunner;

class CartManagerTest extends TestCase
{
    private PDO $pdo;
    private PdoProductRepository $productRepo;
    private PdoCartManager $cartManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = ConnectionFactory::create([
            'driver' => 'sqlite',
            'path' => ':memory:',
        ]);
        $runner = new MigrationRunner($this->pdo);
        $runner->migrateUp();

        $this->productRepo = new PdoProductRepository($this->pdo);
        $taxEngine = new TaxEngine($this->pdo);
        $shippingCalc = new ShippingCalculator($this->pdo);
        $this->cartManager = new PdoCartManager($this->pdo, $this->productRepo, $taxEngine, $shippingCalc);
    }

    public function testAddAndRetrieveCartItems(): void
    {
        $prod = $this->productRepo->save(new Product(
            id: null,
            name: 'Bookshelf Speaker',
            slug: 'bookshelf-speaker',
            sku: 'SPK-01',
            type: ProductType::Simple,
            price: new Money(2500, 'USD'),
            stockQuantity: 10,
            stockStatus: StockStatus::InStock
        ));

        $cart = $this->cartManager->getOrCreateCart('token_abc');
        $item = $this->cartManager->addItem($cart, (int)$prod->getId(), 2);

        $this->assertSame(2, $item->getQuantity());
        $this->assertSame(5000, $item->getLineTotal()->getAmount());

        $reloaded = $this->cartManager->getCartByToken('token_abc');
        $this->assertNotNull($reloaded);
        $this->assertSame(1, $reloaded->getItemCount() === 2 ? 1 : count($reloaded->getItems()));
        $this->assertSame(5000, $reloaded->getSubtotal()->getAmount());
    }

    public function testAddZeroOrNegativeQuantityThrowsException(): void
    {
        $prod = $this->productRepo->save(new Product(
            id: null,
            name: 'Cable',
            slug: 'cable',
            sku: 'CBL-01',
            type: ProductType::Simple,
            price: new Money(1000, 'USD'),
            stockQuantity: 10
        ));

        $cart = $this->cartManager->getOrCreateCart('token_zero');

        $this->expectException(InvalidArgumentException::class);
        $this->cartManager->addItem($cart, (int)$prod->getId(), 0);
    }

    public function testAddQuantityExceedingStockThrowsException(): void
    {
        $prod = $this->productRepo->save(new Product(
            id: null,
            name: 'Limited Edition Amp',
            slug: 'limited-amp',
            sku: 'AMP-01',
            type: ProductType::Simple,
            price: new Money(50000, 'USD'),
            stockQuantity: 2
        ));

        $cart = $this->cartManager->getOrCreateCart('token_stock');

        $this->expectException(InsufficientStockException::class);
        $this->cartManager->addItem($cart, (int)$prod->getId(), 5);
    }

    public function testCouponAndCompoundPricingMath(): void
    {
        // Insert tax zone for WA (10.25%, $7.50 flat shipping)
        $this->pdo->exec(
            "INSERT INTO tax_zones (country, state, name, rate, flat_shipping_rate, free_shipping_threshold)
             VALUES ('US', 'WA', 'Washington', 0.1025, 750, 15000)"
        );

        // Insert coupon SAVE15 (15% percentage discount)
        $this->pdo->exec(
            "INSERT INTO coupons (code, discount_type, discount_value, min_subtotal, is_active)
             VALUES ('SAVE15', 'percentage', 15, 0, 1)"
        );

        // Add 3x Product A ($19.99 = 1999) + 1x Product B ($4.95 = 495) -> 6492 cents
        $prodA = $this->productRepo->save(new Product(
            id: null,
            name: 'Product A',
            slug: 'prod-a',
            sku: 'A-01',
            type: ProductType::Simple,
            price: new Money(1999, 'USD'),
            stockQuantity: 10
        ));

        $prodB = $this->productRepo->save(new Product(
            id: null,
            name: 'Product B',
            slug: 'prod-b',
            sku: 'B-01',
            type: ProductType::Simple,
            price: new Money(495, 'USD'),
            stockQuantity: 10
        ));

        $cart = $this->cartManager->getOrCreateCart('token_math');
        $this->cartManager->addItem($cart, (int)$prodA->getId(), 3);
        $this->cartManager->addItem($cart, (int)$prodB->getId(), 1);

        $cart = $this->cartManager->getCartByToken('token_math');
        $this->assertSame(6492, $cart->getSubtotal()->getAmount());

        // Apply coupon
        $cartWithCoupon = $this->cartManager->applyCoupon($cart, 'SAVE15');
        $this->assertSame('SAVE15', $cartWithCoupon->getCouponCode());

        // Calculate totals for WA
        $totals = $this->cartManager->calculateTotals($cartWithCoupon, 'WA');

        // Discount: 15% of 6492 = 974 cents
        $this->assertSame(974, $totals->getDiscountAmount()->getAmount());

        // Discounted Subtotal: 6492 - 974 = 5518 cents
        $this->assertSame(5518, $totals->getDiscountedSubtotal()->getAmount());

        // Tax: 10.25% on 5518 = 566 cents
        $this->assertSame(566, $totals->getTaxAmount()->getAmount());

        // Shipping: flat rate $7.50 = 750 cents
        $this->assertSame(750, $totals->getShippingAmount()->getAmount());

        // Grand Total: 5518 + 750 + 566 = 6834 cents ($68.34)
        $this->assertSame(6834, $totals->getGrandTotal()->getAmount());
        $this->assertSame('$68.34', $totals->getGrandTotal()->format());
    }

    public function testInvalidOrExpiredCouponThrowsException(): void
    {
        $this->pdo->exec(
            "INSERT INTO coupons (code, discount_type, discount_value, expires_at, is_active)
             VALUES ('EXPIRED_2020', 'fixed', 500, '2020-01-01 00:00:00', 1)"
        );

        $cart = $this->cartManager->getOrCreateCart('token_coupon');

        $this->expectException(InvalidCouponException::class);
        $this->cartManager->applyCoupon($cart, 'EXPIRED_2020');
    }

    public function testGuestToCustomerAutoMerge(): void
    {
        // Create products 101 ($25.00) and 102 ($10.00)
        $prod101 = $this->productRepo->save(new Product(
            id: null,
            name: 'P101',
            slug: 'p101',
            sku: 'P-101',
            type: ProductType::Simple,
            price: new Money(2500, 'USD'),
            stockQuantity: 20
        ));

        $prod102 = $this->productRepo->save(new Product(
            id: null,
            name: 'P102',
            slug: 'p102',
            sku: 'P-102',
            type: ProductType::Simple,
            price: new Money(1000, 'USD'),
            stockQuantity: 20
        ));

        // Create customer record in customers table
        $this->pdo->exec(
            "INSERT INTO customers (id, email, password_hash, first_name, last_name)
             VALUES (888, 'jane.doe@example.com', 'hash', 'Jane', 'Doe')"
        );

        // Create customer cart with 2x P102
        $custCart = $this->cartManager->getOrCreateCart('token_cust', 888);
        $this->cartManager->addItem($custCart, (int)$prod102->getId(), 2);

        // Guest adds 2x P101 and 3x P102
        $guestCart = $this->cartManager->getOrCreateCart('token_guest');
        $this->cartManager->addItem($guestCart, (int)$prod101->getId(), 2);
        $this->cartManager->addItem($guestCart, (int)$prod102->getId(), 3);

        // Auto-merge guest cart into customer cart
        $merged = $this->cartManager->mergeGuestCartIntoCustomerCart('token_guest', 888);

        // Customer cart now has 2x P101 ($50.00) and (2+3)=5x P102 ($50.00) = $100.00 (10000 cents)
        $this->assertSame(10000, $merged->getSubtotal()->getAmount());

        $itemP101 = $merged->findItemByProduct((int)$prod101->getId());
        $this->assertNotNull($itemP101);
        $this->assertSame(2, $itemP101->getQuantity());

        $itemP102 = $merged->findItemByProduct((int)$prod102->getId());
        $this->assertNotNull($itemP102);
        $this->assertSame(5, $itemP102->getQuantity());

        // Guest cart should be deleted
        $this->assertNull($this->cartManager->getCartByToken('token_guest'));
    }
}
