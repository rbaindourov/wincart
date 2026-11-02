<?php

declare(strict_types=1);

namespace WinCart\Tests\Feature;

use PDO;
use PHPUnit\Framework\TestCase;
use WinCart\Catalog\Repositories\PdoProductRepository;
use WinCart\Checkout\OrderStateMachine;
use WinCart\Checkout\PdoOrderRepository;
use WinCart\Common\OrderStatus;
use WinCart\Database\ConnectionFactory;
use WinCart\Database\MigrationRunner;
use WinCart\Database\TestFixtureSeeder;
use WinCart\Http\Kernel;

/**
 * Empirical Adversarial Challenger M4-i2-2 Test Suite.
 *
 * Exhaustive empirical verification of Customer Order Isolation (IDOR defense),
 * RFC 7807 compliance, and Admin privilege override on all order mutation endpoints:
 *   - PATCH /api/v1/orders/{id}/status
 *   - POST /api/v1/orders/{id}/cancel
 *   - POST /api/v1/orders/{id}/refund
 */
final class ChallengerM4CustomerOrderIsolationStressTest extends TestCase
{
    private PDO $pdo;
    private Kernel $kernel;
    private PdoOrderRepository $orderRepo;
    private PdoProductRepository $productRepo;
    private OrderStateMachine $stateMachine;
    private string $tempDbFile;
    private string $tempLockfile;

    private string $tokenCustomerA = 'pat_test_challenger_cust_a_orders_rw';
    private string $tokenCustomerB = 'pat_test_challenger_cust_b_orders_rw';
    private string $tokenCustomerAReadOnly = 'pat_test_challenger_cust_a_orders_ro';
    private string $tokenAdmin = 'pat_test_challenger_admin_scope';

    private int $customerIdA = 301;
    private int $customerIdB = 302;
    private int $adminId = 300;

    private int $orderIdCustomerBPending1 = 9501;
    private int $orderIdCustomerBPending2 = 9502;
    private int $orderIdCustomerBCompleted = 9503;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDbFile = sys_get_temp_dir() . '/wincart_m4_challenger_' . uniqid() . '.sqlite';
        $this->pdo = ConnectionFactory::create([
            'driver' => 'sqlite',
            'path' => $this->tempDbFile,
        ]);

        $runner = new MigrationRunner($this->pdo);
        $runner->migrateUp();

        TestFixtureSeeder::seed($this->pdo);

        $this->productRepo = new PdoProductRepository($this->pdo);
        $this->orderRepo = new PdoOrderRepository($this->pdo);
        $this->stateMachine = new OrderStateMachine($this->pdo, $this->orderRepo, $this->productRepo);

        $this->seedCustomers();
        $this->seedOrders();

        $this->tempLockfile = sys_get_temp_dir() . '/wincart_m4_ch_lock_' . uniqid() . '.lock';
        touch($this->tempLockfile);

        $this->kernel = new Kernel([
            'DB_DATABASE' => $this->tempDbFile,
            'INSTALL_LOCK_PATH' => $this->tempLockfile,
        ]);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempLockfile)) {
            @unlink($this->tempLockfile);
        }
        if (file_exists($this->tempDbFile)) {
            @unlink($this->tempDbFile);
        }
        parent::tearDown();
    }

    private function seedCustomers(): void
    {
        $now = date('Y-m-d H:i:s');
        $hash = password_hash('Pass123!', PASSWORD_BCRYPT, ['cost' => 4]);

        $customers = [
            [
                'id' => $this->customerIdA,
                'email' => 'challenger-cust-a@test.local',
                'role' => 'customer',
                'token' => $this->tokenCustomerA,
                'scopes' => 'orders:read,orders:write',
            ],
            [
                'id' => $this->customerIdB,
                'email' => 'challenger-cust-b@test.local',
                'role' => 'customer',
                'token' => $this->tokenCustomerB,
                'scopes' => 'orders:read,orders:write',
            ],
            [
                'id' => 303,
                'email' => 'challenger-cust-a-ro@test.local',
                'role' => 'customer',
                'token' => $this->tokenCustomerAReadOnly,
                'scopes' => 'orders:read',
            ],
            [
                'id' => $this->adminId,
                'email' => 'challenger-admin@test.local',
                'role' => 'admin',
                'token' => $this->tokenAdmin,
                'scopes' => 'admin',
            ],
        ];

        $stmt = $this->pdo->prepare('INSERT INTO customers (
            id, email, password_hash, first_name, last_name, role, pat_token_hash,
            pat_scopes, is_active, created_at, updated_at
        ) VALUES (
            :id, :email, :pass, :fname, :lname, :role, :token_hash, :scopes, 1, :now, :now
        )');

        foreach ($customers as $c) {
            $stmt->execute([
                ':id' => $c['id'],
                ':email' => $c['email'],
                ':pass' => $hash,
                ':fname' => 'Challenger',
                ':lname' => 'User',
                ':role' => $c['role'],
                ':token_hash' => hash('sha256', $c['token']),
                ':scopes' => $c['scopes'],
                ':now' => $now,
            ]);
        }
    }

    private function seedOrders(): void
    {
        $now = date('Y-m-d H:i:s');

        $orders = [
            [
                'id' => $this->orderIdCustomerBPending1,
                'order_number' => 'ORD-CH-9501',
                'customer_id' => $this->customerIdB,
                'status' => 'pending',
                'grand_total' => 6500,
            ],
            [
                'id' => $this->orderIdCustomerBPending2,
                'order_number' => 'ORD-CH-9502',
                'customer_id' => $this->customerIdB,
                'status' => 'pending',
                'grand_total' => 4200,
            ],
            [
                'id' => $this->orderIdCustomerBCompleted,
                'order_number' => 'ORD-CH-9503',
                'customer_id' => $this->customerIdB,
                'status' => 'completed',
                'grand_total' => 8900,
            ],
        ];

        $stmt = $this->pdo->prepare('INSERT INTO orders (
            id, order_number, customer_id, status, currency, subtotal, discount_amount,
            tax_amount, shipping_amount, grand_total,
            billing_first_name, billing_last_name, billing_address_1, billing_city,
            billing_state, billing_postal_code, billing_country,
            shipping_first_name, shipping_last_name, shipping_address_1, shipping_city,
            shipping_state, shipping_postal_code, shipping_country,
            payment_method, created_at, updated_at
        ) VALUES (
            :id, :order_number, :customer_id, :status, "USD", :grand_total, 0, 0, 0, :grand_total,
            "CustB", "Tester", "456 Oak St", "Seattle", "WA", "98101", "US",
            "CustB", "Tester", "456 Oak St", "Seattle", "WA", "98101", "US",
            "mock", :now, :now
        )');

        foreach ($orders as $o) {
            $stmt->execute([
                ':id' => $o['id'],
                ':order_number' => $o['order_number'],
                ':customer_id' => $o['customer_id'],
                ':status' => $o['status'],
                ':grand_total' => $o['grand_total'],
                ':now' => $now,
            ]);
        }
    }

    /**
     * Attack Scenario 1: Customer A attempts PATCH /api/v1/orders/{orderIdCustomerB}/status.
     * Must return strictly HTTP 403 Forbidden RFC 7807 ('Access denied to requested order resource').
     * Customer B's order status must remain UNCHANGED.
     */
    public function testCustomerACannotPatchCustomerBOrderStatusStrictly403AndUnchanged(): void
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->tokenCustomerA,
            'Content-Type' => 'application/json',
        ];
        $payload = json_encode(['status' => 'processing']);

        $response = $this->kernel->handleRequest(
            'PATCH',
            '/api/v1/orders/' . $this->orderIdCustomerBPending1 . '/status',
            $payload,
            $headers
        );

        $this->assertSame(403, $response->getStatusCode(), 'IDOR status mutation must return 403 Forbidden');
        $this->assertStringContainsString('application/problem+json', $response->getHeaderLine('Content-Type'));

        $body = json_decode($response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertSame('https://tools.ietf.org/html/rfc7807', $body['type']);
        $this->assertSame('Forbidden', $body['title']);
        $this->assertSame(403, $body['status']);
        $this->assertSame('Access denied to requested order resource', $body['detail']);

        // Verify Customer B's order status is UNCHANGED in database
        $order = $this->orderRepo->findById($this->orderIdCustomerBPending1);
        $this->assertNotNull($order);
        $this->assertSame(
            OrderStatus::Pending,
            $order->getStatus(),
            'Customer B order status must remain UNCHANGED (Pending) after blocked attack'
        );
    }

    /**
     * Attack Scenario 2: Customer A attempts POST /api/v1/orders/{orderIdCustomerB}/cancel.
     * Must return strictly HTTP 403 Forbidden RFC 7807 ('Access denied to requested order resource').
     * Customer B's order must NOT be cancelled.
     */
    public function testCustomerACannotCancelCustomerBOrderStrictly403AndNotCancelled(): void
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->tokenCustomerA,
            'Content-Type' => 'application/json',
        ];
        $payload = json_encode(['reason' => 'Unauthorized cancellation attempt by competitor']);

        $response = $this->kernel->handleRequest(
            'POST',
            '/api/v1/orders/' . $this->orderIdCustomerBPending2 . '/cancel',
            $payload,
            $headers
        );

        $this->assertSame(403, $response->getStatusCode(), 'IDOR cancel must return 403 Forbidden');
        $this->assertStringContainsString('application/problem+json', $response->getHeaderLine('Content-Type'));

        $body = json_decode($response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertSame('https://tools.ietf.org/html/rfc7807', $body['type']);
        $this->assertSame('Forbidden', $body['title']);
        $this->assertSame(403, $body['status']);
        $this->assertSame('Access denied to requested order resource', $body['detail']);

        // Verify Customer B's order is NOT cancelled in database
        $order = $this->orderRepo->findById($this->orderIdCustomerBPending2);
        $this->assertNotNull($order);
        $this->assertSame(
            OrderStatus::Pending,
            $order->getStatus(),
            'Customer B order must NOT be cancelled after blocked attack'
        );
    }

    /**
     * Attack Scenario 3: Customer A attempts POST /api/v1/orders/{orderIdCustomerB}/refund.
     * Must return strictly HTTP 403 Forbidden RFC 7807 ('Access denied to requested order resource').
     * Customer B's order must NOT be refunded.
     */
    public function testCustomerACannotRefundCustomerBOrderStrictly403AndNotRefunded(): void
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->tokenCustomerA,
            'Content-Type' => 'application/json',
        ];
        $payload = json_encode([
            'reason' => 'Unauthorized refund attempt',
            'restock' => false,
        ]);

        $response = $this->kernel->handleRequest(
            'POST',
            '/api/v1/orders/' . $this->orderIdCustomerBCompleted . '/refund',
            $payload,
            $headers
        );

        $this->assertSame(403, $response->getStatusCode(), 'IDOR refund must return 403 Forbidden');
        $this->assertStringContainsString('application/problem+json', $response->getHeaderLine('Content-Type'));

        $body = json_decode($response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertSame('https://tools.ietf.org/html/rfc7807', $body['type']);
        $this->assertSame('Forbidden', $body['title']);
        $this->assertSame(403, $body['status']);
        $this->assertSame('Access denied to requested order resource', $body['detail']);

        // Verify Customer B's order is NOT refunded in database
        $order = $this->orderRepo->findById($this->orderIdCustomerBCompleted);
        $this->assertNotNull($order);
        $this->assertSame(
            OrderStatus::Completed,
            $order->getStatus(),
            'Customer B order must remain Completed and NOT refunded after blocked attack'
        );
    }

    /**
     * Scenario 4: Admin (with admin scope) CAN transition Customer B's order -> HTTP 200.
     */
    public function testAdminCanTransitionCustomerBOrderToProcessing(): void
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->tokenAdmin,
            'Content-Type' => 'application/json',
        ];
        $payload = json_encode(['status' => 'processing']);

        $response = $this->kernel->handleRequest(
            'PATCH',
            '/api/v1/orders/' . $this->orderIdCustomerBPending1 . '/status',
            $payload,
            $headers
        );

        $this->assertSame(200, $response->getStatusCode(), 'Admin must be authorized to transition order status');
        $body = json_decode($response->getBody(), true);
        $this->assertSame('processing', $body['status']);

        // Verify DB update
        $order = $this->orderRepo->findById($this->orderIdCustomerBPending1);
        $this->assertNotNull($order);
        $this->assertSame(OrderStatus::Processing, $order->getStatus());
    }

    /**
     * Scenario 5: Admin (with admin scope) CAN cancel Customer B's order -> HTTP 200.
     */
    public function testAdminCanCancelCustomerBOrder(): void
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->tokenAdmin,
            'Content-Type' => 'application/json',
        ];
        $payload = json_encode(['reason' => 'Legitimate admin order cancellation']);

        $response = $this->kernel->handleRequest(
            'POST',
            '/api/v1/orders/' . $this->orderIdCustomerBPending2 . '/cancel',
            $payload,
            $headers
        );

        $this->assertSame(200, $response->getStatusCode(), 'Admin must be authorized to cancel orders');
        $body = json_decode($response->getBody(), true);
        $this->assertSame('cancelled', $body['status']);

        // Verify DB update
        $order = $this->orderRepo->findById($this->orderIdCustomerBPending2);
        $this->assertNotNull($order);
        $this->assertSame(OrderStatus::Cancelled, $order->getStatus());
    }

    /**
     * Scenario 6: Admin (with admin scope) CAN refund Customer B's order -> HTTP 200.
     */
    public function testAdminCanRefundCustomerBOrder(): void
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->tokenAdmin,
            'Content-Type' => 'application/json',
        ];
        $payload = json_encode([
            'reason' => 'Customer requested return and refund',
            'restock' => false,
        ]);

        $response = $this->kernel->handleRequest(
            'POST',
            '/api/v1/orders/' . $this->orderIdCustomerBCompleted . '/refund',
            $payload,
            $headers
        );

        $this->assertSame(200, $response->getStatusCode(), 'Admin must be authorized to refund orders');
        $body = json_decode($response->getBody(), true);
        $this->assertSame('refunded', $body['status']);

        // Verify DB update
        $order = $this->orderRepo->findById($this->orderIdCustomerBCompleted);
        $this->assertNotNull($order);
        $this->assertSame(OrderStatus::Refunded, $order->getStatus());
    }

    /**
     * Scenario 7: Parameter Tampering on Mutation Endpoints.
     * Customer A includes "?customer_id=302" or payload "customer_id: 302" in PATCH/cancel.
     * Kernel must still resolve auth identity from token, rejecting with 403 Forbidden.
     */
    public function testCustomerAParameterTamperingOnMutationFailsStrictly403(): void
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->tokenCustomerA,
            'Content-Type' => 'application/json',
        ];
        $payload = json_encode([
            'status' => 'cancelled',
            'customer_id' => $this->customerIdB,
        ]);

        $response = $this->kernel->handleRequest(
            'PATCH',
            '/api/v1/orders/' . $this->orderIdCustomerBPending1 . '/status?customer_id=' . $this->customerIdB,
            $payload,
            $headers
        );

        $this->assertSame(403, $response->getStatusCode(), 'Spoofed customer_id param must not bypass isolation');
        $body = json_decode($response->getBody(), true);
        $this->assertSame('Access denied to requested order resource', $body['detail']);
    }

    /**
     * Scenario 8: Token with only orders:read attempting to mutate own order.
     * Customer A (read-only token) attempts PATCH on order #9501.
     * Must be rejected at perimeter with 403 Forbidden
     * ('Token does not possess required privilege scope: orders:write').
     */
    public function testReadOnlyTokenRejectedAtPerimeterScopeGate(): void
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->tokenCustomerAReadOnly,
            'Content-Type' => 'application/json',
        ];
        $payload = json_encode(['status' => 'processing']);

        $response = $this->kernel->handleRequest(
            'PATCH',
            '/api/v1/orders/' . $this->orderIdCustomerBPending1 . '/status',
            $payload,
            $headers
        );

        $this->assertSame(403, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertStringContainsString(
            'Token does not possess required privilege scope: orders:write',
            $body['detail']
        );
    }
}
