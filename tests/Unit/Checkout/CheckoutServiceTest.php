<?php

declare(strict_types=1);

namespace WinCart\Tests\Unit\Checkout;

use PDO;
use PHPUnit\Framework\TestCase;
use WinCart\Cart\Services\PdoCartManager;
use WinCart\Catalog\Exceptions\InsufficientStockException;
use WinCart\Catalog\Models\Product;
use WinCart\Catalog\Repositories\PdoProductRepository;
use WinCart\Checkout\CheckoutService;
use WinCart\Checkout\Exceptions\InvalidOrderStateTransitionException;
use WinCart\Checkout\Exceptions\PaymentFailedException;
use WinCart\Checkout\OrderStateMachine;
use WinCart\Checkout\PdoOrderRepository;
use WinCart\Common\Address;
use WinCart\Common\Money;
use WinCart\Common\OrderStatus;
use WinCart\Common\ProductType;
use WinCart\Common\StockStatus;
use WinCart\Database\ConnectionFactory;
use WinCart\Database\MigrationRunner;
use WinCart\Payment\MockPaymentGateway;
use WinCart\Payment\PaymentGatewayRegistry;
use WinCart\Payment\PaymentRequest;

class CheckoutServiceTest extends TestCase
{
    private PDO $pdo;
    private PdoProductRepository $productRepo;
    private PdoOrderRepository $orderRepo;
    private PdoCartManager $cartManager;
    private CheckoutService $checkoutService;
    private OrderStateMachine $fsm;
    private MockPaymentGateway $gateway;

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
        $this->orderRepo = new PdoOrderRepository($this->pdo);
        $this->cartManager = new PdoCartManager($this->pdo, $this->productRepo);

        $registry = new PaymentGatewayRegistry();
        $this->gateway = new MockPaymentGateway();
        $registry->register($this->gateway);

        $this->checkoutService = new CheckoutService(
            $this->pdo,
            $this->orderRepo,
            $this->productRepo,
            $registry,
            $this->cartManager
        );

        $this->fsm = new OrderStateMachine($this->pdo, $this->orderRepo, $this->productRepo);
    }

    public function testMockPaymentGatewayTokens(): void
    {
        $amount = new Money(5000, 'USD');

        // Success token
        $req1 = new PaymentRequest($amount, 'tok_mock_success', 'REF-01');
        $res1 = $this->gateway->processPayment($req1);
        $this->assertTrue($res1->isSuccessful());
        $this->assertNotNull($res1->getTransactionId());

        // Decline token
        $req2 = new PaymentRequest($amount, 'tok_mock_decline', 'REF-02');
        $res2 = $this->gateway->processPayment($req2);
        $this->assertFalse($res2->isSuccessful());
        $this->assertStringContainsString('declined', (string)$res2->getErrorMessage());

        // Error token
        $req3 = new PaymentRequest($amount, 'tok_mock_error', 'REF-03');
        $res3 = $this->gateway->processPayment($req3);
        $this->assertFalse($res3->isSuccessful());
        $this->assertStringContainsString('communication error', (string)$res3->getErrorMessage());
    }

    public function testCheckoutOrderSubmissionAndCartClearing(): void
    {
        $prod = $this->productRepo->save(new Product(
            id: null,
            name: 'DAC',
            slug: 'dac',
            sku: 'DAC-01',
            type: ProductType::Simple,
            price: new Money(9900, 'USD'),
            stockQuantity: 5,
            stockStatus: StockStatus::InStock
        ));

        $cart = $this->cartManager->getOrCreateCart('token_checkout');
        $this->cartManager->addItem($cart, (int)$prod->getId(), 2);

        $address = new Address(
            firstName: 'Alice',
            lastName: 'Smith',
            address1: '123 Main St',
            city: 'Seattle',
            state: 'WA',
            postalCode: '98101',
            country: 'US'
        );

        $order = $this->checkoutService->submitOrder($cart, [
            'billing_address' => $address,
            'shipping_address' => $address,
            'payment_method' => 'mock',
            'payment_token' => 'tok_mock_success',
        ]);

        $this->assertNotNull($order->getId());
        $this->assertSame(OrderStatus::Processing, $order->getStatus());
        $this->assertSame(19800, $order->getSubtotal()->getAmount());

        // Stock was reduced from 5 to 3
        $reloadedProd = $this->productRepo->findById((int)$prod->getId());
        $this->assertSame(3, $reloadedProd->getStockQuantity());

        // Cart items were cleared
        $reloadedCart = $this->cartManager->getCartByToken('token_checkout');
        $this->assertTrue($reloadedCart->isEmpty());
    }

    public function testCheckoutFailsAndRollsBackOnStockDeficit(): void
    {
        $prod = $this->productRepo->save(new Product(
            id: null,
            name: 'Rare Item',
            slug: 'rare-item',
            sku: 'RARE-01',
            type: ProductType::Simple,
            price: new Money(1000, 'USD'),
            stockQuantity: 1
        ));

        $this->expectException(InsufficientStockException::class);

        $this->checkoutService->submitOrder(null, [
            'items' => [
                ['product_id' => (int)$prod->getId(), 'quantity' => 2],
            ],
            'payment_method' => 'mock',
            'payment_token' => 'tok_mock_success',
        ]);

        // Stock remains 1
        $reloaded = $this->productRepo->findById((int)$prod->getId());
        $this->assertSame(1, $reloaded->getStockQuantity());
    }

    public function testCheckoutFailsAndRollsBackOnPaymentDecline(): void
    {
        $prod = $this->productRepo->save(new Product(
            id: null,
            name: 'Priceless Amp',
            slug: 'priceless-amp',
            sku: 'AMP-99',
            type: ProductType::Simple,
            price: new Money(10000, 'USD'),
            stockQuantity: 4
        ));

        $this->expectException(PaymentFailedException::class);

        try {
            $this->checkoutService->submitOrder(null, [
                'items' => [
                    ['product_id' => (int)$prod->getId(), 'quantity' => 1],
                ],
                'payment_method' => 'mock',
                'payment_token' => 'tok_mock_decline',
            ]);
        } finally {
            // Stock must remain rolled back at 4
            $reloaded = $this->productRepo->findById((int)$prod->getId());
            $this->assertSame(4, $reloaded->getStockQuantity());
        }
    }

    public function testOrderStateMachineTransitionsAndRestock(): void
    {
        $prod = $this->productRepo->save(new Product(
            id: null,
            name: 'Preamp',
            slug: 'preamp',
            sku: 'PRE-01',
            type: ProductType::Simple,
            price: new Money(5000, 'USD'),
            stockQuantity: 10
        ));

        $address = new Address(
            firstName: 'Bob',
            lastName: 'Jones',
            address1: '456 Oak St',
            city: 'Springfield',
            state: 'IL',
            postalCode: '62701',
            country: 'US'
        );

        $order = $this->checkoutService->submitOrder(null, [
            'items' => [
                ['product_id' => (int)$prod->getId(), 'quantity' => 2],
            ],
            'billing_address' => $address,
            'shipping_address' => $address,
            'payment_method' => 'mock',
            'payment_token' => 'tok_mock_success',
        ]);

        // Remaining stock is now 8
        $this->assertSame(8, $this->productRepo->findById((int)$prod->getId())->getStockQuantity());

        // Transition Processing -> Completed
        $order = $this->fsm->transition($order, OrderStatus::Completed);
        $this->assertSame(OrderStatus::Completed, $order->getStatus());

        // Attempt invalid transition Completed -> Pending: throws InvalidOrderStateTransitionException
        $this->expectException(InvalidOrderStateTransitionException::class);
        $this->fsm->transition($order, OrderStatus::Pending);
    }
}
