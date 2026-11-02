<?php

declare(strict_types=1);

namespace WinCart\Tests\Feature;

use PDO;
use PHPUnit\Framework\TestCase;
use WinCart\Catalog\Repositories\PdoProductRepository;
use WinCart\Checkout\Order;
use WinCart\Checkout\OrderStateMachine;
use WinCart\Checkout\PdoOrderRepository;
use WinCart\Common\OrderStatus;
use WinCart\Database\ConnectionFactory;
use WinCart\Database\MigrationRunner;
use WinCart\Database\TestFixtureSeeder;
use WinCart\Http\Kernel;
use WinCart\Mcp\McpServer;
use WinCart\Mcp\Tools\CreateProductTool;
use WinCart\Mcp\Tools\SearchProductsTool;
use WinCart\Mcp\Tools\ToolRegistry;
use WinCart\Mcp\Tools\UpdateOrderStatusTool;

/**
 * Challenger M4-2: Adversarial Empirical Challenge & Stress Test Suite.
 *
 * Verifies Milestone 4:
 * 1. Granular Scope Enforcement (products:read, orders:read, products:write, cart:read, admin)
 * 2. Customer Order Isolation (scoping, direct object reference IDOR prevention, parameter spoofing)
 * 3. MCP Tool Suite Edge Cases (negative prices, string prices, special chars/XSS, non-existent orders,
 *    illegal FSM transitions, SQLi, Unicode)
 * 4. Adversarial Security Probing (unauthenticated mutations, validation vs auth order)
 */
class ChallengerM4TwoStressTest extends TestCase
{
    private PDO $pdo;
    private PdoProductRepository $productRepo;
    private PdoOrderRepository $orderRepo;
    private OrderStateMachine $stateMachine;
    private Kernel $kernel;
    private string $tempLockfile;
    private string $tempDbFile;

    // Tokens created for granular scope tests
    private string $tokenProductsRead = 'pat_test_products_read_only_xyz';
    private string $tokenOrdersRead = 'pat_test_orders_read_only_xyz';
    private string $tokenProductsWrite = 'pat_test_products_write_only_xyz';
    private string $tokenCartRead = 'pat_test_cart_read_only_xyz';
    private string $tokenCustomerA = 'pat_test_customer_a_orders_read_write';
    private string $tokenCustomerB = 'pat_test_customer_b_orders_read_write';
    private string $tokenAdmin = 'pat_test_super_admin_all_scopes';

    private int $customerIdA = 201;
    private int $customerIdB = 202;
    private int $orderIdCustomerA = 9001;
    private int $orderIdCustomerB = 9002;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDbFile = sys_get_temp_dir() . '/wincart_m4_test_db_' . uniqid() . '.sqlite';
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

        $this->seedGranularTestCustomers();
        $this->seedCustomerOrders();

        $this->tempLockfile = sys_get_temp_dir() . '/wincart_m4_lock_' . uniqid() . '.lock';
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

    private static ?string $cachedPasswordHash = null;

    private function getPasswordHash(): string
    {
        if (self::$cachedPasswordHash === null) {
            self::$cachedPasswordHash = password_hash('Pass123!', PASSWORD_BCRYPT, ['cost' => 4]);
        }
        return self::$cachedPasswordHash;
    }

    private function seedGranularTestCustomers(): void
    {
        $now = date('Y-m-d H:i:s');
        $customers = [
            [
                'id' => 101,
                'email' => 'prod-reader@test.local',
                'role' => 'customer',
                'token' => $this->tokenProductsRead,
                'scopes' => 'products:read',
            ],
            [
                'id' => 102,
                'email' => 'ord-reader@test.local',
                'role' => 'customer',
                'token' => $this->tokenOrdersRead,
                'scopes' => 'orders:read',
            ],
            [
                'id' => 103,
                'email' => 'prod-writer@test.local',
                'role' => 'customer',
                'token' => $this->tokenProductsWrite,
                'scopes' => 'products:write',
            ],
            [
                'id' => 104,
                'email' => 'cart-reader@test.local',
                'role' => 'customer',
                'token' => $this->tokenCartRead,
                'scopes' => 'cart:read',
            ],
            [
                'id' => $this->customerIdA,
                'email' => 'cust-a@test.local',
                'role' => 'customer',
                'token' => $this->tokenCustomerA,
                'scopes' => 'orders:read,orders:write',
            ],
            [
                'id' => $this->customerIdB,
                'email' => 'cust-b@test.local',
                'role' => 'customer',
                'token' => $this->tokenCustomerB,
                'scopes' => 'orders:read,orders:write',
            ],
            [
                'id' => 105,
                'email' => 'admin-m4@test.local',
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

        $hash = $this->getPasswordHash();
        foreach ($customers as $c) {
            $stmt->execute([
                ':id' => $c['id'],
                ':email' => $c['email'],
                ':pass' => $hash,
                ':fname' => 'Test',
                ':lname' => 'User',
                ':role' => $c['role'],
                ':token_hash' => hash('sha256', $c['token']),
                ':scopes' => $c['scopes'],
                ':now' => $now,
            ]);
        }
    }

    private function seedCustomerOrders(): void
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
            :id, :num, :cid, :status, "USD", 10000, 0, 0, 0, 10000,
            "Cust", "User", "123 Test St", "Seattle", "WA", "98101", "US",
            "Cust", "User", "123 Test St", "Seattle", "WA", "98101", "US",
            "mock", :now, :now
        )');

        // Customer A's order
        $stmt->execute([
            ':id' => $this->orderIdCustomerA,
            ':num' => 'ORD-CUST-A-001',
            ':cid' => $this->customerIdA,
            ':status' => 'pending',
            ':now' => $now,
        ]);

        // Customer B's order
        $stmt->execute([
            ':id' => $this->orderIdCustomerB,
            ':num' => 'ORD-CUST-B-001',
            ':cid' => $this->customerIdB,
            ':status' => 'completed',
            ':now' => $now,
        ]);
    }

    // =========================================================================
    // SECTION 1: Granular Scope Enforcement Tests
    // =========================================================================

    /**
     * Probe: Token with only products:read attempting POST /api/v1/products
     * Assert strictly 403 Forbidden RFC 7807 (never 200, 401, or 500).
     */
    public function testGranularScopeEnforcementProductsReadBlocksPostProductStrictly403Rfc7807(): void
    {
        $payload = json_encode([
            'name' => 'Unauthorized Widget',
            'sku' => 'UNAUTH-PROD-01',
            'price' => 1999,
            'stock_quantity' => 10,
        ]);

        $headers = [
            'Authorization' => 'Bearer ' . $this->tokenProductsRead,
            'Content-Type' => 'application/json',
        ];

        $response = $this->kernel->handleRequest('POST', '/api/v1/products', $payload, $headers);

        $this->assertSame(403, $response->getStatusCode(), 'Must strictly return 403 Forbidden');
        $this->assertStringContainsString('application/problem+json', $response->getHeaderLine('Content-Type'));

        $body = json_decode($response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertSame('https://tools.ietf.org/html/rfc7807', $body['type']);
        $this->assertSame('Forbidden', $body['title']);
        $this->assertSame(403, $body['status']);
        $this->assertStringContainsString('Token does not possess required privilege scope', $body['detail']);
    }

    /**
     * Probe: Token with only products:read attempting DELETE /api/v1/products/1
     * Assert strictly 403 Forbidden RFC 7807 (never 200, 401, or 500).
     */
    public function testGranularScopeEnforcementProductsReadBlocksDeleteProductStrictly403Rfc7807(): void
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->tokenProductsRead,
        ];

        $response = $this->kernel->handleRequest('DELETE', '/api/v1/products/1', null, $headers);

        $this->assertSame(403, $response->getStatusCode(), 'Must strictly return 403 Forbidden');
        $this->assertStringContainsString('application/problem+json', $response->getHeaderLine('Content-Type'));

        $body = json_decode($response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertSame('https://tools.ietf.org/html/rfc7807', $body['type']);
        $this->assertSame('Forbidden', $body['title']);
        $this->assertSame(403, $body['status']);
        $this->assertStringContainsString('Token does not possess required privilege scope', $body['detail']);
    }

    /**
     * Probe: Token with only orders:read attempting PATCH /api/v1/orders/1/status
     * Assert strictly 403 Forbidden RFC 7807 (never 200, 401, or 500).
     */
    public function testGranularScopeEnforcementOrdersReadBlocksPatchOrderStatusStrictly403Rfc7807(): void
    {
        $payload = json_encode(['status' => 'completed']);
        $headers = [
            'Authorization' => 'Bearer ' . $this->tokenOrdersRead,
            'Content-Type' => 'application/json',
        ];

        $response = $this->kernel->handleRequest('PATCH', '/api/v1/orders/1/status', $payload, $headers);

        $this->assertSame(403, $response->getStatusCode(), 'Must strictly return 403 Forbidden');
        $this->assertStringContainsString('application/problem+json', $response->getHeaderLine('Content-Type'));

        $body = json_decode($response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertSame('https://tools.ietf.org/html/rfc7807', $body['type']);
        $this->assertSame('Forbidden', $body['title']);
        $this->assertSame(403, $body['status']);
        $this->assertStringContainsString(
            'Token does not possess required privilege scope: orders:write',
            $body['detail']
        );
    }

    /**
     * Probe: Token with only cart:read attempting GET /api/v1/admin/metrics
     * Assert strictly 403 Forbidden RFC 7807 (never 200, 401, or 500).
     */
    public function testGranularScopeEnforcementCartReadBlocksAdminMetricsStrictly403Rfc7807(): void
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->tokenCartRead,
        ];

        $response = $this->kernel->handleRequest('GET', '/api/v1/admin/metrics', null, $headers);

        $this->assertSame(403, $response->getStatusCode(), 'Must strictly return 403 Forbidden');
        $this->assertStringContainsString('application/problem+json', $response->getHeaderLine('Content-Type'));

        $body = json_decode($response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertSame('https://tools.ietf.org/html/rfc7807', $body['type']);
        $this->assertSame('Forbidden', $body['title']);
        $this->assertSame(403, $body['status']);
        $this->assertStringContainsString('Admin privileges required', $body['detail']);
    }

    /**
     * Probe: Token with products:write CAN perform POST /api/v1/products -> 201 Created.
     */
    public function testGranularScopeEnforcementProductsWriteAllowsCreation(): void
    {
        $payload = json_encode([
            'name' => 'Authorized Write Product',
            'sku' => 'AUTH-WRITE-001',
            'price' => 3500,
            'stock_quantity' => 15,
        ]);
        $headers = [
            'Authorization' => 'Bearer ' . $this->tokenProductsWrite,
            'Content-Type' => 'application/json',
        ];

        $response = $this->kernel->handleRequest('POST', '/api/v1/products', $payload, $headers);
        $this->assertSame(201, $response->getStatusCode(), 'products:write scope must permit product creation');

        $body = json_decode($response->getBody(), true);
        $this->assertSame('AUTH-WRITE-001', $body['sku']);
    }

    /**
     * Probe: Token with products:write attempting PATCH /api/v1/orders/1/status -> 403 Forbidden.
     */
    public function testGranularScopeEnforcementProductsWriteCannotModifyOrders(): void
    {
        $payload = json_encode(['status' => 'processing']);
        $headers = [
            'Authorization' => 'Bearer ' . $this->tokenProductsWrite,
            'Content-Type' => 'application/json',
        ];

        $response = $this->kernel->handleRequest('PATCH', '/api/v1/orders/1/status', $payload, $headers);
        $this->assertSame(403, $response->getStatusCode());
    }

    // =========================================================================
    // SECTION 2: Customer Order Isolation Tests
    // =========================================================================

    /**
     * Authenticate with Customer A's token. Attempt GET /api/v1/orders -> assert ONLY Customer A's orders are returned.
     */
    public function testCustomerOrderIsolationReturnsOnlyOwnOrders(): void
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->tokenCustomerA,
        ];

        $response = $this->kernel->handleRequest('GET', '/api/v1/orders', null, $headers);
        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('data', $body);

        $orders = $body['data'];
        $this->assertCount(1, $orders, 'Customer A must receive exactly 1 order (their own)');
        $this->assertSame($this->orderIdCustomerA, (int)$orders[0]['id']);
        $this->assertSame($this->customerIdA, (int)$orders[0]['customer_id']);

        // Assert Customer B's order is NOT leaked
        foreach ($orders as $ord) {
            $this->assertNotSame($this->orderIdCustomerB, (int)$ord['id'], 'Must not leak Customer B order');
            $this->assertSame($this->customerIdA, (int)$ord['customer_id']);
        }
    }

    /**
     * Attempt GET /api/v1/orders/{order_id_of_customer_b} with Customer A's token
     * -> assert strictly 403 Forbidden RFC 7807 (no data leakage).
     */
    public function testCustomerOrderIsolationBlocksAccessToCustomerBOrderStrictly403Rfc7807(): void
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->tokenCustomerA,
        ];

        $targetUrl = '/api/v1/orders/' . $this->orderIdCustomerB;
        $response = $this->kernel->handleRequest('GET', $targetUrl, null, $headers);

        $this->assertSame(403, $response->getStatusCode(), 'IDOR probe must be blocked with 403 Forbidden');
        $this->assertStringContainsString('application/problem+json', $response->getHeaderLine('Content-Type'));

        $body = json_decode($response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertSame('https://tools.ietf.org/html/rfc7807', $body['type']);
        $this->assertSame('Forbidden', $body['title']);
        $this->assertSame(403, $body['status']);
        $this->assertSame('Access denied to requested order resource', $body['detail']);

        // Assert no order payload leakage
        $this->assertArrayNotHasKey('order_number', $body);
        $this->assertArrayNotHasKey('grand_total', $body);
        $this->assertArrayNotHasKey('items', $body);
    }

    /**
     * Parameter Tampering: Customer A attempts to spoof customer_id parameter on GET /api/v1/orders?customer_id=202
     * Assert parameter is completely ignored and still only Customer A's orders are returned.
     */
    public function testCustomerOrderIsolationIgnoresCustomerParameterSpoofing(): void
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->tokenCustomerA,
        ];

        $targetUrl = '/api/v1/orders?customer_id=' . $this->customerIdB;
        $response = $this->kernel->handleRequest('GET', $targetUrl, null, $headers);
        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        $orders = $body['data'];
        $this->assertCount(1, $orders);
        $this->assertSame($this->customerIdA, (int)$orders[0]['customer_id']);
    }

    // =========================================================================
    // SECTION 3: MCP Tool Suite Edge Cases
    // =========================================================================

    /**
     * create_product: negative price (-500)
     * Assert tool execution error returned gracefully (never unhandled crash).
     */
    public function testMcpCreateProductWithNegativePriceFailsGracefully(): void
    {
        $tool = new CreateProductTool($this->pdo, $this->productRepo);
        $registry = new ToolRegistry();
        $registry->register($tool);

        $result = $registry->call('create_product', [
            'name' => 'Negative Price Gadget',
            'sku' => 'NEG-PRICE-01',
            'price' => -500,
        ]);

        $this->assertTrue($result->isError(), 'Must return error for negative price');
        $content = $result->getContent();
        $this->assertNotEmpty($content);
        $this->assertStringContainsString('Money amount cannot be negative: -500', $content[0]['text']);
    }

    /**
     * create_product: string price variants
     */
    public function testMcpCreateProductWithStringPriceBehaviors(): void
    {
        $tool = new CreateProductTool($this->pdo, $this->productRepo);
        $registry = new ToolRegistry();
        $registry->register($tool);

        // Numeric string '4999' should work cleanly
        $resNumeric = $registry->call('create_product', [
            'name' => 'String Price Product',
            'sku' => 'STR-PRICE-01',
            'price' => '4999',
        ]);
        $this->assertFalse($resNumeric->isError(), 'Numeric string price should succeed');
        $parsed = json_decode($resNumeric->getContent()[0]['text'], true);
        $this->assertSame(4999, $parsed['price']);

        // Missing price entirely
        $resMissing = $registry->call('create_product', [
            'name' => 'No Price Product',
            'sku' => 'NO-PRICE-01',
        ]);
        $this->assertTrue($resMissing->isError(), 'Missing price must return error');
        $this->assertStringContainsString(
            "Product 'price' or 'price_cents' is required",
            $resMissing->getContent()[0]['text']
        );
    }

    /**
     * create_product: special characters in title and HTML tags
     * Verify slug generation, XSS containment, and database persistence.
     */
    public function testMcpCreateProductWithSpecialCharactersInTitle(): void
    {
        $tool = new CreateProductTool($this->pdo, $this->productRepo);
        $registry = new ToolRegistry();
        $registry->register($tool);

        $xssTitle = '<script>alert("XSS")</script> & "Vintage" @#1 🚀 Widget';
        $result = $registry->call('create_product', [
            'name' => $xssTitle,
            'sku' => 'SPEC-CHAR-01',
            'price' => 2500,
        ]);

        $this->assertFalse($result->isError(), 'Creation with special chars should succeed');
        $parsed = json_decode($result->getContent()[0]['text'], true);
        $this->assertSame($xssTitle, $parsed['name']);

        // Slug must sanitize special characters
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $parsed['slug']);
        $this->assertStringNotContainsString('<', $parsed['slug']);
        $this->assertStringNotContainsString('"', $parsed['slug']);

        // Verify product was saved in DB
        $saved = $this->productRepo->findById((int)$parsed['id']);
        $this->assertNotNull($saved);
        $this->assertSame($xssTitle, $saved->getName());
    }

    /**
     * update_order_status: non-existent order (e.g. ID 999999)
     * Assert returns isError: true with "Order #999999 not found".
     */
    public function testMcpUpdateOrderStatusWithNonExistentOrder(): void
    {
        $tool = new UpdateOrderStatusTool($this->pdo, $this->stateMachine, $this->orderRepo);
        $registry = new ToolRegistry();
        $registry->register($tool);

        $result = $registry->call('update_order_status', [
            'id' => 999999,
            'status' => 'completed',
        ]);

        $this->assertTrue($result->isError(), 'Must fail for non-existent order');
        $content = $result->getContent();
        $this->assertStringContainsString('Order #999999 not found', $content[0]['text']);
    }

    /**
     * update_order_status: illegal FSM transition (Completed -> Pending)
     * Order #9002 was seeded with status 'completed'. Attempt to transition to 'pending'.
     */
    public function testMcpUpdateOrderStatusIllegalFsmTransitionCompletedToPending(): void
    {
        $tool = new UpdateOrderStatusTool($this->pdo, $this->stateMachine, $this->orderRepo);
        $registry = new ToolRegistry();
        $registry->register($tool);

        $result = $registry->call('update_order_status', [
            'id' => $this->orderIdCustomerB,
            'status' => 'pending',
        ]);

        $this->assertTrue($result->isError(), 'Illegal FSM transition must fail');
        $content = $result->getContent();
        $this->assertStringContainsString('Cannot transition order', $content[0]['text']);
        $this->assertStringContainsString('"completed" to "pending"', $content[0]['text']);
    }

    /**
     * update_order_status: non-numeric ID
     * Assert returns isError: true with "Missing or invalid 'id' or 'order_id'".
     */
    public function testMcpUpdateOrderStatusWithNonNumericId(): void
    {
        $tool = new UpdateOrderStatusTool($this->pdo, $this->stateMachine, $this->orderRepo);
        $registry = new ToolRegistry();
        $registry->register($tool);

        $result = $registry->call('update_order_status', [
            'id' => 'non-numeric-id-xyz',
            'status' => 'completed',
        ]);

        $this->assertTrue($result->isError(), 'Non-numeric ID must return error');
        $content = $result->getContent();
        $this->assertStringContainsString("Missing or invalid 'id' or 'order_id'", $content[0]['text']);
    }

    /**
     * search_products: empty string query
     * Assert returns whole catalog without crashing.
     */
    public function testMcpSearchProductsEmptyString(): void
    {
        $tool = new SearchProductsTool($this->productRepo);
        $registry = new ToolRegistry();
        $registry->register($tool);

        $result = $registry->call('search_products', [
            'query' => '',
        ]);

        $this->assertFalse($result->isError());
        $payload = json_decode($result->getContent()[0]['text'], true);
        $this->assertGreaterThan(0, $payload['total'], 'Catalog search with empty query must return total products');
        $this->assertNotEmpty($payload['products']);
    }

    /**
     * search_products: SQL injection payload (' OR 1=1 --)
     * Assert parameterized queries prevent tautological injection.
     */
    public function testMcpSearchProductsSqlInjectionPayloadSafelyEscaped(): void
    {
        $tool = new SearchProductsTool($this->productRepo);
        $registry = new ToolRegistry();
        $registry->register($tool);

        $sqli = "' OR 1=1 --";
        $result = $registry->call('search_products', [
            'query' => $sqli,
        ]);

        $this->assertFalse($result->isError());
        $payload = json_decode($result->getContent()[0]['text'], true);

        // SQL injection must NOT return all products; it should only match if literal name contains "' OR 1=1 --"
        $this->assertSame(0, $payload['total'], 'SQL injection must not match all products');
        $this->assertEmpty($payload['products']);
    }

    /**
     * search_products: Unicode and emoji characters
     * Assert robust handling of multi-byte UTF-8 without database collation or encoding faults.
     */
    public function testMcpSearchProductsUnicodeCharacters(): void
    {
        $tool = new SearchProductsTool($this->productRepo);
        $registry = new ToolRegistry();
        $registry->register($tool);

        $unicodeQuery = '🚀 日本語 Électronique 🎧';
        $result = $registry->call('search_products', [
            'query' => $unicodeQuery,
        ]);

        $this->assertFalse($result->isError());
        $payload = json_decode($result->getContent()[0]['text'], true);
        $this->assertSame($unicodeQuery, $payload['query']);
        $this->assertSame(0, $payload['total']);
    }

    // =========================================================================
    // SECTION 4: Adversarial Security Probing & Regression Guards
    // =========================================================================

    /**
     * Regression Guard 1: Mandatory Authentication on PATCH /api/v1/orders/{id}/status.
     *
     * Unauthenticated callers omitting Authorization header MUST receive strictly 401 Unauthorized
     * with RFC 7807 Problem Details envelope. Order status in database must remain unmodified.
     */
    public function testSecurityGuardUnauthenticatedPatchOrderStatusStrictlyRejectedWith401(): void
    {
        // Order #9001 is in 'pending' status
        $order = $this->orderRepo->findById($this->orderIdCustomerA);
        $this->assertSame(OrderStatus::Pending, $order->getStatus());

        // Probe with ZERO Authorization header
        $payload = json_encode(['status' => 'cancelled']);
        $response = $this->kernel->handleRequest(
            'PATCH',
            '/api/v1/orders/' . $this->orderIdCustomerA . '/status',
            $payload,
            ['Content-Type' => 'application/json']
        );

        $this->assertSame(
            401,
            $response->getStatusCode(),
            'Must strictly return 401 Unauthorized when token is omitted on PATCH /api/v1/orders/{id}/status'
        );
        $this->assertStringContainsString('application/problem+json', $response->getHeaderLine('Content-Type'));

        $body = json_decode($response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertSame('https://tools.ietf.org/html/rfc7807', $body['type']);
        $this->assertSame('Unauthorized', $body['title']);
        $this->assertSame(401, $body['status']);
        $this->assertSame('Authentication token required', $body['detail']);

        // Assert database state: order status MUST NOT have changed
        $updatedOrder = $this->orderRepo->findById($this->orderIdCustomerA);
        $this->assertSame(
            OrderStatus::Pending,
            $updatedOrder->getStatus(),
            'Order status must remain unmodified when unauthenticated'
        );
    }

    /**
     * Regression Guard 2: Authorization Precedes Payload Validation on POST /api/v1/products.
     *
     * When unauthorized caller (e.g. token with products:read only) sends invalid payload lacking price,
     * the kernel must reject upfront with 403 Forbidden RFC 7807 (never leaking 422 Unprocessable Entity).
     * Similarly, an unauthenticated request with invalid payload must be rejected upfront with 401 Unauthorized.
     */
    public function testSecurityGuardAuthorizationPrecedesPayloadValidation(): void
    {
        // 1. Unauthorized token with only products:read sends body missing price -> must return 403, NOT 422
        $payload = json_encode(['name' => 'Bad Payload Missing Price']);
        $headers = [
            'Authorization' => 'Bearer ' . $this->tokenProductsRead,
            'Content-Type' => 'application/json',
        ];

        $response = $this->kernel->handleRequest('POST', '/api/v1/products', $payload, $headers);

        $this->assertSame(
            403,
            $response->getStatusCode(),
            'Must return 403 Forbidden before 422 validation on unauthorized write'
        );
        $this->assertStringContainsString('application/problem+json', $response->getHeaderLine('Content-Type'));
        $body = json_decode($response->getBody(), true);
        $this->assertSame('Forbidden', $body['title']);
        $this->assertSame(403, $body['status']);
        $this->assertStringContainsString('Token does not possess required privilege scope', $body['detail']);

        // 2. Unauthenticated request with bad payload -> must return 401 Unauthorized, NOT 422
        $unauthResponse = $this->kernel->handleRequest('POST', '/api/v1/products', $payload, [
            'Content-Type' => 'application/json',
        ]);
        $this->assertSame(
            401,
            $unauthResponse->getStatusCode(),
            'Must return 401 Unauthorized before 422 validation on unauthenticated write'
        );
        $unauthBody = json_decode($unauthResponse->getBody(), true);
        $this->assertSame('Unauthorized', $unauthBody['title']);
        $this->assertSame(401, $unauthBody['status']);
    }

    /**
     * Regression Guard 3: Mandatory Authentication on POST /api/v1/orders/{id}/cancel.
     *
     * Unauthenticated callers omitting Authorization header MUST receive strictly 401 Unauthorized
     * with RFC 7807 Problem Details envelope. Order in database must NOT be cancelled.
     */
    public function testSecurityGuardUnauthenticatedOrderCancellationStrictlyRejectedWith401(): void
    {
        // Create a pending order #9005
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
            9005, "ORD-9005", 201, "pending", "USD", 5000, 0, 0, 0, 5000,
            "C", "U", "1 St", "S", "WA", "98101", "US",
            "C", "U", "1 St", "S", "WA", "98101", "US",
            "mock", :now, :now
        )');
        $stmt->execute([':now' => $now]);

        // Attempt cancellation WITHOUT token
        $payload = json_encode(['reason' => 'Unauthenticated adversary cancel']);
        $response = $this->kernel->handleRequest('POST', '/api/v1/orders/9005/cancel', $payload, [
            'Content-Type' => 'application/json',
        ]);

        $this->assertSame(
            401,
            $response->getStatusCode(),
            'Must strictly return 401 Unauthorized when token is omitted on POST /api/v1/orders/{id}/cancel'
        );
        $this->assertStringContainsString('application/problem+json', $response->getHeaderLine('Content-Type'));

        $body = json_decode($response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertSame('Unauthorized', $body['title']);
        $this->assertSame(401, $body['status']);
        $this->assertSame('Authentication token required', $body['detail']);

        // Assert database state: order must remain pending
        $order = $this->orderRepo->findById(9005);
        $this->assertSame(
            OrderStatus::Pending,
            $order->getStatus(),
            'Order #9005 must NOT be cancelled without authentication'
        );
    }

    /**
     * Regression Guard 4: Mandatory Authentication on POST /api/v1/orders/{id}/refund.
     *
     * Unauthenticated callers omitting Authorization header MUST receive strictly 401 Unauthorized
     * with RFC 7807 Problem Details envelope. Order in database must NOT be refunded.
     */
    public function testSecurityGuardUnauthenticatedOrderRefundStrictlyRejectedWith401(): void
    {
        // Customer B's order #9002 is in 'completed' status
        $order = $this->orderRepo->findById($this->orderIdCustomerB);
        $this->assertSame(OrderStatus::Completed, $order->getStatus());

        // Attempt refund WITHOUT token
        $payload = json_encode([
            'reason' => 'Unauthenticated refund attempt',
            'restock' => true,
        ]);
        $response = $this->kernel->handleRequest(
            'POST',
            '/api/v1/orders/' . $this->orderIdCustomerB . '/refund',
            $payload,
            ['Content-Type' => 'application/json']
        );

        $this->assertSame(
            401,
            $response->getStatusCode(),
            'Must strictly return 401 Unauthorized when token is omitted on POST /api/v1/orders/{id}/refund'
        );
        $this->assertStringContainsString('application/problem+json', $response->getHeaderLine('Content-Type'));

        $body = json_decode($response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertSame('Unauthorized', $body['title']);
        $this->assertSame(401, $body['status']);
        $this->assertSame('Authentication token required', $body['detail']);

        // Assert database state: order must remain completed
        $orderAfter = $this->orderRepo->findById($this->orderIdCustomerB);
        $this->assertSame(
            OrderStatus::Completed,
            $orderAfter->getStatus(),
            'Order #9002 must NOT be refunded without authentication'
        );
    }

    /**
     * Regression Guard 5: Customer Order Mutation IDOR Isolation.
     *
     * Customer A possesses valid 'orders:write' scope, but attempts to mutate, cancel, or refund
     * Customer B's order #9002. All mutation endpoints must reject with 403 Forbidden RFC 7807.
     */
    public function testCustomerCannotMutateAnotherCustomersOrder(): void
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->tokenCustomerA,
            'Content-Type' => 'application/json',
        ];

        // 1. Customer A attempts to mutate status of Customer B's order #9002
        $statusPayload = json_encode(['status' => 'cancelled']);
        $resStatus = $this->kernel->handleRequest(
            'PATCH',
            '/api/v1/orders/' . $this->orderIdCustomerB . '/status',
            $statusPayload,
            $headers
        );
        $this->assertSame(403, $resStatus->getStatusCode(), 'Cross-tenant status mutation must return 403');
        $bodyStatus = json_decode($resStatus->getBody(), true);
        $this->assertSame('Forbidden', $bodyStatus['title']);
        $this->assertSame(403, $bodyStatus['status']);
        $this->assertSame('Access denied to requested order resource', $bodyStatus['detail']);

        // 2. Customer A attempts to cancel Customer B's order #9002
        $cancelPayload = json_encode(['reason' => 'Adversarial cross-tenant cancel']);
        $resCancel = $this->kernel->handleRequest(
            'POST',
            '/api/v1/orders/' . $this->orderIdCustomerB . '/cancel',
            $cancelPayload,
            $headers
        );
        $this->assertSame(403, $resCancel->getStatusCode(), 'Cross-tenant cancellation must return 403');
        $bodyCancel = json_decode($resCancel->getBody(), true);
        $this->assertSame('Forbidden', $bodyCancel['title']);
        $this->assertSame(403, $bodyCancel['status']);
        $this->assertSame('Access denied to requested order resource', $bodyCancel['detail']);

        // 3. Customer A attempts to refund Customer B's order #9002
        $refundPayload = json_encode(['reason' => 'Adversarial cross-tenant refund']);
        $resRefund = $this->kernel->handleRequest(
            'POST',
            '/api/v1/orders/' . $this->orderIdCustomerB . '/refund',
            $refundPayload,
            $headers
        );
        $this->assertSame(403, $resRefund->getStatusCode(), 'Cross-tenant refund must return 403');
        $bodyRefund = json_decode($resRefund->getBody(), true);
        $this->assertSame('Forbidden', $bodyRefund['title']);
        $this->assertSame(403, $bodyRefund['status']);
        $this->assertSame('Access denied to requested order resource', $bodyRefund['detail']);

        // Assert database state: Customer B's order remains completed and untampered
        $orderB = $this->orderRepo->findById($this->orderIdCustomerB);
        $this->assertSame(
            OrderStatus::Completed,
            $orderB->getStatus(),
            'Customer B order must remain in Completed status after rejected IDOR attacks'
        );
    }
}
