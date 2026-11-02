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
 * Challenger M4-i2-1: Empirical Adversarial Stress Test Suite for Milestone 4 Remediation.
 *
 * Exhaustively stress-tests:
 * 1. PATCH /api/v1/orders/{id}/status:
 *    - Zero Authorization header (401)
 *    - Empty Bearer token (401)
 *    - Invalid Bearer token (401)
 *    - Wrong scope: orders:read (403)
 *    - Wrong scope: products:write (403)
 *    - Wrong scope: cart:read (403)
 *    - Cross-tenant IDOR attack (403)
 *    - Database immutability verification for each rejected probe
 * 2. POST /api/v1/orders/{id}/cancel:
 *    - Zero Authorization header (401)
 *    - Empty Bearer token (401)
 *    - Invalid Bearer token (401)
 *    - Wrong scope: orders:read (403)
 *    - Cross-tenant IDOR attack (403)
 *    - Database immutability verification for each rejected probe
 * 3. POST /api/v1/orders/{id}/refund:
 *    - Zero Authorization header (401)
 *    - Empty Bearer token (401)
 *    - Invalid Bearer token (401)
 *    - Wrong scope: orders:read (403)
 *    - Cross-tenant IDOR attack (403)
 *    - Database immutability verification for each rejected probe
 * 4. POST /api/v1/products:
 *    - Empty payload {} with zero token -> 401 before 422
 *    - Empty payload {} with empty Bearer token -> 401 before 422
 *    - Empty payload {} with invalid token -> 401 before 422
 *    - Empty payload {} with token lacking products:write -> 403 before 422
 *    - Non-numeric price with zero token -> 401 before 422
 *    - Non-numeric price with token lacking products:write -> 403 before 422
 *    - Non-numeric price with valid products:write token -> 422 Unprocessable Entity
 */
class ChallengerM4RemediationAdversarialTest extends TestCase
{
    private PDO $pdo;
    private PdoOrderRepository $orderRepo;
    private PdoProductRepository $productRepo;
    private Kernel $kernel;
    private string $tempLockfile;
    private string $tempDbFile;

    private string $tokenProductsRead = 'pat_adv_products_read_only';
    private string $tokenOrdersRead = 'pat_adv_orders_read_only';
    private string $tokenProductsWrite = 'pat_adv_products_write_only';
    private string $tokenCartRead = 'pat_adv_cart_read_only';
    private string $tokenCustomerA = 'pat_adv_customer_a_orders_rw';
    private string $tokenCustomerB = 'pat_adv_customer_b_orders_rw';
    private string $tokenAdmin = 'pat_adv_admin_all_scopes';

    private int $customerIdA = 301;
    private int $customerIdB = 302;
    private int $orderIdPendingA = 9101;
    private int $orderIdCompletedB = 9102;
    private int $orderIdPendingCancel = 9103;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDbFile = sys_get_temp_dir() . '/wincart_adv_m4_' . uniqid() . '.sqlite';
        $this->pdo = ConnectionFactory::create([
            'driver' => 'sqlite',
            'path' => $this->tempDbFile,
        ]);

        $runner = new MigrationRunner($this->pdo);
        $runner->migrateUp();
        TestFixtureSeeder::seed($this->pdo);

        $this->productRepo = new PdoProductRepository($this->pdo);
        $this->orderRepo = new PdoOrderRepository($this->pdo);

        $this->seedAdversarialCustomers();
        $this->seedAdversarialOrders();

        $this->tempLockfile = sys_get_temp_dir() . '/wincart_adv_m4_lock_' . uniqid() . '.lock';
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

    private function seedAdversarialCustomers(): void
    {
        $now = date('Y-m-d H:i:s');
        $pwd = password_hash('Pass123!', PASSWORD_BCRYPT, ['cost' => 4]);

        $customers = [
            [
                'id' => 111,
                'email' => 'adv-prod-r@test.local',
                'role' => 'customer',
                'token' => $this->tokenProductsRead,
                'scopes' => 'products:read',
            ],
            [
                'id' => 112,
                'email' => 'adv-ord-r@test.local',
                'role' => 'customer',
                'token' => $this->tokenOrdersRead,
                'scopes' => 'orders:read',
            ],
            [
                'id' => 113,
                'email' => 'adv-prod-w@test.local',
                'role' => 'customer',
                'token' => $this->tokenProductsWrite,
                'scopes' => 'products:write',
            ],
            [
                'id' => 114,
                'email' => 'adv-cart-r@test.local',
                'role' => 'customer',
                'token' => $this->tokenCartRead,
                'scopes' => 'cart:read',
            ],
            [
                'id' => $this->customerIdA,
                'email' => 'adv-cust-a@test.local',
                'role' => 'customer',
                'token' => $this->tokenCustomerA,
                'scopes' => 'orders:read,orders:write',
            ],
            [
                'id' => $this->customerIdB,
                'email' => 'adv-cust-b@test.local',
                'role' => 'customer',
                'token' => $this->tokenCustomerB,
                'scopes' => 'orders:read,orders:write',
            ],
            [
                'id' => 999,
                'email' => 'adv-admin@test.local',
                'role' => 'admin',
                'token' => $this->tokenAdmin,
                'scopes' => 'admin',
            ],
        ];

        $stmt = $this->pdo->prepare('INSERT INTO customers (
            id, email, password_hash, first_name, last_name, role, is_active,
            pat_token_hash, pat_scopes, created_at, updated_at
        ) VALUES (
            :id, :email, :pwd, "Adv", "User", :role, 1,
            :token_hash, :scopes, :now, :now
        )');

        foreach ($customers as $c) {
            $stmt->execute([
                ':id' => $c['id'],
                ':email' => $c['email'],
                ':pwd' => $pwd,
                ':role' => $c['role'],
                ':token_hash' => hash('sha256', $c['token']),
                ':scopes' => $c['scopes'],
                ':now' => $now,
            ]);
        }
    }

    private function seedAdversarialOrders(): void
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('INSERT INTO orders (
            id, order_number, customer_id, status, currency, subtotal, discount_amount,
            tax_amount, shipping_amount, grand_total,
            billing_first_name, billing_last_name, billing_address_1, billing_city,
            billing_state, billing_postal_code, billing_country,
            shipping_first_name, shipping_last_name, shipping_address_1, shipping_city,
            shipping_state, shipping_postal_code, shipping_country,
            payment_method, created_at, updated_at
        ) VALUES (
            :id, :order_number, :customer_id, :status, "USD", 5000, 0, 0, 0, 5000,
            "Adv", "Tester", "100 Security Blvd", "Cyber", "WA", "98101", "US",
            "Adv", "Tester", "100 Security Blvd", "Cyber", "WA", "98101", "US",
            "mock", :now, :now
        )');

        $stmt->execute([
            ':id' => $this->orderIdPendingA,
            ':order_number' => 'ORD-9101',
            ':customer_id' => $this->customerIdA,
            ':status' => 'pending',
            ':now' => $now,
        ]);

        $stmt->execute([
            ':id' => $this->orderIdCompletedB,
            ':order_number' => 'ORD-9102',
            ':customer_id' => $this->customerIdB,
            ':status' => 'completed',
            ':now' => $now,
        ]);

        $stmt->execute([
            ':id' => $this->orderIdPendingCancel,
            ':order_number' => 'ORD-9103',
            ':customer_id' => $this->customerIdA,
            ':status' => 'pending',
            ':now' => $now,
        ]);
    }

    private function assertProblemDetails(
        $response,
        int $expectedStatus,
        string $expectedTitle,
        ?string $expectedDetailSubstring = null
    ): array {
        $this->assertSame($expectedStatus, $response->getStatusCode());
        $this->assertStringContainsString('application/problem+json', $response->getHeaderLine('Content-Type'));

        $body = json_decode($response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertSame('https://tools.ietf.org/html/rfc7807', $body['type'] ?? '');
        $this->assertSame($expectedTitle, $body['title'] ?? '');
        $this->assertSame($expectedStatus, $body['status'] ?? 0);

        if ($expectedDetailSubstring !== null) {
            $this->assertStringContainsString($expectedDetailSubstring, $body['detail'] ?? '');
        }

        return $body;
    }

    // =========================================================================
    // SECTION 1: PATCH /api/v1/orders/{id}/status
    // =========================================================================

    public function testProbePatchOrderStatusZeroAuthHeader(): void
    {
        $payload = json_encode(['status' => 'processing']);
        $path = '/api/v1/orders/' . $this->orderIdPendingA . '/status';
        $response = $this->kernel->handleRequest('PATCH', $path, $payload, [
            'Content-Type' => 'application/json',
        ]);

        $this->assertProblemDetails($response, 401, 'Unauthorized', 'Authentication token required');

        $order = $this->orderRepo->findById($this->orderIdPendingA);
        $this->assertSame(OrderStatus::Pending, $order->getStatus());
    }

    public function testProbePatchOrderStatusEmptyBearerToken(): void
    {
        $payload = json_encode(['status' => 'processing']);
        $path = '/api/v1/orders/' . $this->orderIdPendingA . '/status';
        $response = $this->kernel->handleRequest('PATCH', $path, $payload, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ',
        ]);

        $this->assertProblemDetails($response, 401, 'Unauthorized', 'Authentication token required');

        $order = $this->orderRepo->findById($this->orderIdPendingA);
        $this->assertSame(OrderStatus::Pending, $order->getStatus());
    }

    public function testProbePatchOrderStatusInvalidBearerToken(): void
    {
        $payload = json_encode(['status' => 'processing']);
        $path = '/api/v1/orders/' . $this->orderIdPendingA . '/status';
        $response = $this->kernel->handleRequest('PATCH', $path, $payload, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer invalid_adversarial_token_12345',
        ]);

        $this->assertProblemDetails($response, 401, 'Unauthorized', 'Authentication token required');

        $order = $this->orderRepo->findById($this->orderIdPendingA);
        $this->assertSame(OrderStatus::Pending, $order->getStatus());
    }

    public function testProbePatchOrderStatusScopeOrdersRead(): void
    {
        $payload = json_encode(['status' => 'processing']);
        $path = '/api/v1/orders/' . $this->orderIdPendingA . '/status';
        $response = $this->kernel->handleRequest('PATCH', $path, $payload, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->tokenOrdersRead,
        ]);

        $this->assertProblemDetails(
            $response,
            403,
            'Forbidden',
            'Token does not possess required privilege scope: orders:write'
        );

        $order = $this->orderRepo->findById($this->orderIdPendingA);
        $this->assertSame(OrderStatus::Pending, $order->getStatus());
    }

    public function testProbePatchOrderStatusScopeProductsWrite(): void
    {
        $payload = json_encode(['status' => 'processing']);
        $path = '/api/v1/orders/' . $this->orderIdPendingA . '/status';
        $response = $this->kernel->handleRequest('PATCH', $path, $payload, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->tokenProductsWrite,
        ]);

        $this->assertProblemDetails(
            $response,
            403,
            'Forbidden',
            'Token does not possess required privilege scope: orders:write'
        );

        $order = $this->orderRepo->findById($this->orderIdPendingA);
        $this->assertSame(OrderStatus::Pending, $order->getStatus());
    }

    public function testProbePatchOrderStatusScopeCartRead(): void
    {
        $payload = json_encode(['status' => 'processing']);
        $path = '/api/v1/orders/' . $this->orderIdPendingA . '/status';
        $response = $this->kernel->handleRequest('PATCH', $path, $payload, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->tokenCartRead,
        ]);

        $this->assertProblemDetails(
            $response,
            403,
            'Forbidden',
            'Token does not possess required privilege scope: orders:write'
        );

        $order = $this->orderRepo->findById($this->orderIdPendingA);
        $this->assertSame(OrderStatus::Pending, $order->getStatus());
    }

    public function testProbePatchOrderStatusIdorCustomerIsolation(): void
    {
        $payload = json_encode(['status' => 'processing']);
        $path = '/api/v1/orders/' . $this->orderIdCompletedB . '/status';
        $response = $this->kernel->handleRequest('PATCH', $path, $payload, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->tokenCustomerA,
        ]);

        $this->assertProblemDetails($response, 403, 'Forbidden', 'Access denied to requested order resource');

        $order = $this->orderRepo->findById($this->orderIdCompletedB);
        $this->assertSame(OrderStatus::Completed, $order->getStatus());
    }

    public function testProbePatchOrderStatusAuthorizedCustomerOwnOrder(): void
    {
        $payload = json_encode(['status' => 'processing']);
        $path = '/api/v1/orders/' . $this->orderIdPendingA . '/status';
        $response = $this->kernel->handleRequest('PATCH', $path, $payload, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->tokenCustomerA,
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('processing', $body['status']);

        $order = $this->orderRepo->findById($this->orderIdPendingA);
        $this->assertSame(OrderStatus::Processing, $order->getStatus());
    }

    // =========================================================================
    // SECTION 2: POST /api/v1/orders/{id}/cancel
    // =========================================================================

    public function testProbePostOrderCancelZeroAuthHeader(): void
    {
        $payload = json_encode(['reason' => 'Zero auth probe']);
        $path = '/api/v1/orders/' . $this->orderIdPendingCancel . '/cancel';
        $response = $this->kernel->handleRequest('POST', $path, $payload, [
            'Content-Type' => 'application/json',
        ]);

        $this->assertProblemDetails($response, 401, 'Unauthorized', 'Authentication token required');

        $order = $this->orderRepo->findById($this->orderIdPendingCancel);
        $this->assertSame(OrderStatus::Pending, $order->getStatus());
    }

    public function testProbePostOrderCancelEmptyBearerToken(): void
    {
        $payload = json_encode(['reason' => 'Empty bearer probe']);
        $path = '/api/v1/orders/' . $this->orderIdPendingCancel . '/cancel';
        $response = $this->kernel->handleRequest('POST', $path, $payload, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ',
        ]);

        $this->assertProblemDetails($response, 401, 'Unauthorized', 'Authentication token required');

        $order = $this->orderRepo->findById($this->orderIdPendingCancel);
        $this->assertSame(OrderStatus::Pending, $order->getStatus());
    }

    public function testProbePostOrderCancelInvalidToken(): void
    {
        $payload = json_encode(['reason' => 'Invalid token probe']);
        $path = '/api/v1/orders/' . $this->orderIdPendingCancel . '/cancel';
        $response = $this->kernel->handleRequest('POST', $path, $payload, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer bad_cancel_token_999',
        ]);

        $this->assertProblemDetails($response, 401, 'Unauthorized', 'Authentication token required');

        $order = $this->orderRepo->findById($this->orderIdPendingCancel);
        $this->assertSame(OrderStatus::Pending, $order->getStatus());
    }

    public function testProbePostOrderCancelWrongScope(): void
    {
        $payload = json_encode(['reason' => 'Wrong scope probe']);
        $path = '/api/v1/orders/' . $this->orderIdPendingCancel . '/cancel';
        $response = $this->kernel->handleRequest('POST', $path, $payload, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->tokenOrdersRead,
        ]);

        $this->assertProblemDetails(
            $response,
            403,
            'Forbidden',
            'Token does not possess required privilege scope: orders:write'
        );

        $order = $this->orderRepo->findById($this->orderIdPendingCancel);
        $this->assertSame(OrderStatus::Pending, $order->getStatus());
    }

    public function testProbePostOrderCancelIdorCustomerIsolation(): void
    {
        $payload = json_encode(['reason' => 'IDOR cancel probe']);
        $path = '/api/v1/orders/' . $this->orderIdCompletedB . '/cancel';
        $response = $this->kernel->handleRequest('POST', $path, $payload, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->tokenCustomerA,
        ]);

        $this->assertProblemDetails($response, 403, 'Forbidden', 'Access denied to requested order resource');

        $order = $this->orderRepo->findById($this->orderIdCompletedB);
        $this->assertSame(OrderStatus::Completed, $order->getStatus());
    }

    public function testProbePostOrderCancelAuthorized(): void
    {
        $payload = json_encode(['reason' => 'Authorized cancellation']);
        $path = '/api/v1/orders/' . $this->orderIdPendingCancel . '/cancel';
        $response = $this->kernel->handleRequest('POST', $path, $payload, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->tokenCustomerA,
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('cancelled', $body['status']);

        $order = $this->orderRepo->findById($this->orderIdPendingCancel);
        $this->assertSame(OrderStatus::Cancelled, $order->getStatus());
    }

    // =========================================================================
    // SECTION 3: POST /api/v1/orders/{id}/refund
    // =========================================================================

    public function testProbePostOrderRefundZeroAuthHeader(): void
    {
        $payload = json_encode(['reason' => 'Zero auth refund probe', 'restock' => true]);
        $path = '/api/v1/orders/' . $this->orderIdCompletedB . '/refund';
        $response = $this->kernel->handleRequest('POST', $path, $payload, [
            'Content-Type' => 'application/json',
        ]);

        $this->assertProblemDetails($response, 401, 'Unauthorized', 'Authentication token required');

        $order = $this->orderRepo->findById($this->orderIdCompletedB);
        $this->assertSame(OrderStatus::Completed, $order->getStatus());
    }

    public function testProbePostOrderRefundEmptyBearerToken(): void
    {
        $payload = json_encode(['reason' => 'Empty bearer refund probe']);
        $path = '/api/v1/orders/' . $this->orderIdCompletedB . '/refund';
        $response = $this->kernel->handleRequest('POST', $path, $payload, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ',
        ]);

        $this->assertProblemDetails($response, 401, 'Unauthorized', 'Authentication token required');

        $order = $this->orderRepo->findById($this->orderIdCompletedB);
        $this->assertSame(OrderStatus::Completed, $order->getStatus());
    }

    public function testProbePostOrderRefundInvalidToken(): void
    {
        $payload = json_encode(['reason' => 'Invalid token refund probe']);
        $path = '/api/v1/orders/' . $this->orderIdCompletedB . '/refund';
        $response = $this->kernel->handleRequest('POST', $path, $payload, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer fake_refund_token_000',
        ]);

        $this->assertProblemDetails($response, 401, 'Unauthorized', 'Authentication token required');

        $order = $this->orderRepo->findById($this->orderIdCompletedB);
        $this->assertSame(OrderStatus::Completed, $order->getStatus());
    }

    public function testProbePostOrderRefundWrongScope(): void
    {
        $payload = json_encode(['reason' => 'Wrong scope refund probe']);
        $path = '/api/v1/orders/' . $this->orderIdCompletedB . '/refund';
        $response = $this->kernel->handleRequest('POST', $path, $payload, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->tokenOrdersRead,
        ]);

        $this->assertProblemDetails(
            $response,
            403,
            'Forbidden',
            'Token does not possess required privilege scope: orders:write'
        );

        $order = $this->orderRepo->findById($this->orderIdCompletedB);
        $this->assertSame(OrderStatus::Completed, $order->getStatus());
    }

    public function testProbePostOrderRefundIdorCustomerIsolation(): void
    {
        $payload = json_encode(['reason' => 'Cross-tenant refund attempt']);
        $path = '/api/v1/orders/' . $this->orderIdCompletedB . '/refund';
        $response = $this->kernel->handleRequest('POST', $path, $payload, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->tokenCustomerA,
        ]);

        $this->assertProblemDetails($response, 403, 'Forbidden', 'Access denied to requested order resource');

        $order = $this->orderRepo->findById($this->orderIdCompletedB);
        $this->assertSame(OrderStatus::Completed, $order->getStatus());
    }

    public function testProbePostOrderRefundAuthorizedAdmin(): void
    {
        $payload = json_encode(['reason' => 'Admin authorized refund']);
        $path = '/api/v1/orders/' . $this->orderIdCompletedB . '/refund';
        $response = $this->kernel->handleRequest('POST', $path, $payload, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->tokenAdmin,
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('refunded', $body['status']);

        $order = $this->orderRepo->findById($this->orderIdCompletedB);
        $this->assertSame(OrderStatus::Refunded, $order->getStatus());
    }

    // =========================================================================
    // SECTION 4: POST /api/v1/products - Auth & Scope Precedes Payload Validation
    // =========================================================================

    public function testProbePostProductsEmptyPayloadZeroAuthHeader(): void
    {
        $response = $this->kernel->handleRequest('POST', '/api/v1/products', '{}', [
            'Content-Type' => 'application/json',
        ]);

        $this->assertProblemDetails($response, 401, 'Unauthorized', 'Authentication token required');
    }

    public function testProbePostProductsEmptyPayloadEmptyBearerToken(): void
    {
        $response = $this->kernel->handleRequest('POST', '/api/v1/products', '{}', [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ',
        ]);

        $this->assertProblemDetails($response, 401, 'Unauthorized', 'Authentication token required');
    }

    public function testProbePostProductsEmptyPayloadInvalidToken(): void
    {
        $response = $this->kernel->handleRequest('POST', '/api/v1/products', '{}', [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer bad_product_token_777',
        ]);

        $this->assertProblemDetails($response, 401, 'Unauthorized', 'Authentication token required');
    }

    public function testProbePostProductsEmptyPayloadWrongScopeProductsRead(): void
    {
        $response = $this->kernel->handleRequest('POST', '/api/v1/products', '{}', [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->tokenProductsRead,
        ]);

        $this->assertProblemDetails(
            $response,
            403,
            'Forbidden',
            'Token does not possess required privilege scope'
        );
    }

    public function testProbePostProductsEmptyPayloadWrongScopeOrdersWrite(): void
    {
        $response = $this->kernel->handleRequest('POST', '/api/v1/products', '{}', [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->tokenOrdersRead,
        ]);

        $this->assertProblemDetails(
            $response,
            403,
            'Forbidden',
            'Token does not possess required privilege scope'
        );
    }

    public function testProbePostProductsNonNumericPriceZeroAuthHeader(): void
    {
        $payload = json_encode([
            'name' => 'Adversarial Price Test',
            'sku' => 'ADV-PRICE-1',
            'price' => 'free-of-charge',
        ]);

        $response = $this->kernel->handleRequest('POST', '/api/v1/products', $payload, [
            'Content-Type' => 'application/json',
        ]);

        $this->assertProblemDetails($response, 401, 'Unauthorized', 'Authentication token required');
    }

    public function testProbePostProductsNonNumericPriceWrongScope(): void
    {
        $payload = json_encode([
            'name' => 'Adversarial Price Test',
            'sku' => 'ADV-PRICE-2',
            'price' => 'unpriced-item',
        ]);

        $response = $this->kernel->handleRequest('POST', '/api/v1/products', $payload, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->tokenProductsRead,
        ]);

        $this->assertProblemDetails(
            $response,
            403,
            'Forbidden',
            'Token does not possess required privilege scope'
        );
    }

    public function testProbePostProductsNonNumericPriceAuthorizedTokenReturns422(): void
    {
        $payload = json_encode([
            'name' => 'Adversarial Price Test',
            'sku' => 'ADV-PRICE-3',
            'price' => 'non-numeric-value',
        ]);

        $response = $this->kernel->handleRequest('POST', '/api/v1/products', $payload, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->tokenProductsWrite,
        ]);

        $this->assertProblemDetails(
            $response,
            422,
            'Unprocessable Entity',
            'Validation failed: price must be numeric'
        );
    }

    public function testProbePostProductsValidAuthorizedTokenReturns201(): void
    {
        $payload = json_encode([
            'name' => 'Valid Adversarial Product',
            'sku' => 'ADV-PROD-201',
            'price' => 3999,
            'stock_quantity' => 25,
            'is_active' => true,
        ]);

        $response = $this->kernel->handleRequest('POST', '/api/v1/products', $payload, [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->tokenProductsWrite,
        ]);

        $this->assertSame(201, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('ADV-PROD-201', $body['sku']);
        $this->assertSame(3999, $body['price']);
    }
}
