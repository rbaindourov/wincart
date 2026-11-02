<?php

declare(strict_types=1);

namespace WinCart\Tests\Feature;

use PDO;
use WinCart\Database\MigrationRunner;
use WinCart\Database\TestFixtureSeeder;
use WinCart\Tests\E2E\Support\OpaqueBoxTestCase;

/**
 * Milestone 3 Adversarial Challenge Suite: Concurrency, Race Conditions & Restock Stress.
 *
 * Authored by Adversarial Challenger M3-1 to stress-test:
 * 1. High-concurrency race condition testing (stock=1, concurrent checkouts, 409 Conflict, RFC 7807, stock >= 0).
 * 2. Multi-item atomic rollback testing (insufficient stock on 1 item -> 0 items deducted).
 * 3. Restock correctness (order cancellation/refund restock: true vs false, out-of-stock transitions).
 * 4. Variable product variation stock isolation (Variation A vs Variation B, atomic rollback, restock).
 */
final class Milestone3CoreEcommerceStressTest extends OpaqueBoxTestCase
{
    private string $workerScript;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireHttp();
        $this->env->createLockfile();

        $pdo = $this->env->getPdo();
        $pdo->exec('PRAGMA journal_mode = WAL;');
        $pdo->exec('PRAGMA busy_timeout = 5000;');

        $runner = new MigrationRunner($pdo, dirname(__DIR__, 2) . '/database/migrations');
        $runner->migrateUp();
        TestFixtureSeeder::seed($pdo);

        $this->workerScript = __DIR__ . '/Support/concurrent_checkout_worker.php';
    }

    private function getFreshPdo(): PDO
    {
        $pdo = new PDO('sqlite:' . $this->env->getDatabasePath(), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON;');
        $pdo->exec('PRAGMA busy_timeout = 5000;');
        return $pdo;
    }

    // =========================================================================
    // 1. High-Concurrency Race Condition Testing (stock_quantity = 1)
    // =========================================================================

    /**
     * Stress Test: 15 concurrent worker processes simultaneously attempting to checkout
     * the single remaining inventory item (stock=1).
     *
     * Verification Requirements:
     * - Exactly 1 checkout succeeds (201 Created).
     * - Subsequent checkouts receive 409 Conflict with RFC 7807 Problem Details.
     * - Inventory count NEVER drops below 0 (stock_quantity is exactly 0, outofstock).
     * - Exactly 1 order is created in database.
     */
    public function testHighConcurrencyRaceConditionSingleStockItem(): void
    {
        $dbPath = $this->env->getDatabasePath();
        $lockPath = $this->env->getLockfilePath();

        // Product 901 initial stock is 1
        $checkPdo = $this->getFreshPdo();
        $initialStock = (int)$checkPdo->query('SELECT stock_quantity FROM products WHERE id = 901')->fetchColumn();
        $this->assertSame(1, $initialStock, 'Pre-condition: Product 901 must have exactly 1 unit in stock');

        // Create barrier file for microsecond synchronization
        $barrierFile = sys_get_temp_dir() . '/wincart_barrier_' . uniqid('', true) . '.lock';
        $barrierFp = fopen($barrierFile, 'w+');
        $this->assertNotFalse($barrierFp);
        flock($barrierFp, LOCK_EX);

        $workerCount = 15;
        $procs = [];
        $outFiles = [];

        for ($i = 0; $i < $workerCount; $i++) {
            $outFile = sys_get_temp_dir() . '/wincart_race_' . $i . '_' . uniqid('', true) . '.json';
            $outFiles[] = $outFile;
            $payload = json_encode([
                'cart_token' => 'cart_race_worker_' . $i . '_' . uniqid('', true),
                'items' => [['product_id' => 901, 'quantity' => 1]],
                'payment_token' => 'tok_mock_success',
            ]);

            $cmd = sprintf(
                'php %s --db=%s --lock=%s --barrier=%s --payload=%s --out=%s',
                escapeshellarg($this->workerScript),
                escapeshellarg($dbPath),
                escapeshellarg($lockPath),
                escapeshellarg($barrierFile),
                escapeshellarg($payload),
                escapeshellarg($outFile)
            );

            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open($cmd, $descriptors, $pipes);
            $this->assertIsResource($proc);
            $procs[] = [$proc, $pipes];
        }

        // Allow all child processes to spawn, boot PHP runtime, and block on barrier
        usleep(300000); // 300ms

        // RELEASE BARRIER: All 15 workers execute checkout simultaneously
        flock($barrierFp, LOCK_UN);
        fclose($barrierFp);

        // Await all workers
        foreach ($procs as [$proc, $pipes]) {
            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            proc_close($proc);
        }

        // Collect and assert results
        $statusCodes = [];
        $conflictResponses = [];
        $successResponses = [];

        foreach ($outFiles as $outFile) {
            $this->assertFileExists($outFile);
            $data = json_decode((string)file_get_contents($outFile), true);
            $this->assertIsArray($data);

            $code = (int)($data['status_code'] ?? 0);
            $statusCodes[] = $code;

            if ($code === 201) {
                $successResponses[] = $data;
            } elseif ($code === 409) {
                $conflictResponses[] = $data;
            }

            @unlink($outFile);
        }
        @unlink($barrierFile);

        // Core Invariant 1: Exactly 1 checkout succeeds
        $this->assertCount(1, $successResponses, 'Exactly 1 concurrent checkout must succeed');
        $this->assertSame(201, $successResponses[0]['status_code']);

        // Core Invariant 2: Exactly (N - 1) checkouts receive 409 Conflict
        $this->assertCount(
            $workerCount - 1,
            $conflictResponses,
            sprintf('Exactly %d competing checkouts must receive 409 Conflict', $workerCount - 1)
        );

        // Core Invariant 3: All 409 responses strictly conform to RFC 7807 Problem Details
        foreach ($conflictResponses as $conflict) {
            $json = $conflict['json'];
            $this->assertIsArray($json, 'Conflict response body must be valid JSON');
            $this->assertSame(409, $json['status']);
            $this->assertSame('Conflict', $json['title']);
            $this->assertSame('https://tools.ietf.org/html/rfc7807', $json['type']);
            $this->assertStringContainsString('Insufficient stock', $json['detail']);
            $this->assertStringContainsString('product #901', $json['detail']);
        }

        // Core Invariant 4: Stock count NEVER drops below 0 in database
        $freshPdo = $this->getFreshPdo();
        $finalStockRow = $freshPdo->query(
            'SELECT stock_quantity, stock_status FROM products WHERE id = 901'
        )->fetch();

        $this->assertSame(0, (int)$finalStockRow['stock_quantity'], 'Inventory must be exactly 0 (never negative)');
        $this->assertGreaterThanOrEqual(0, (int)$finalStockRow['stock_quantity'], 'Inventory must NEVER drop below 0');
        $this->assertSame('outofstock', $finalStockRow['stock_status']);

        // Core Invariant 5: Exactly 1 order record created for Product 901
        $orderCount = (int)$freshPdo->query(
            'SELECT count(*) FROM orders WHERE id NOT IN (1,2,3,4,2001,3001,5001)'
        )->fetchColumn();
        $this->assertSame(1, $orderCount, 'Database must contain exactly 1 new order');

        $orderItemCount = (int)$freshPdo->query(
            'SELECT count(*) FROM order_items WHERE product_id = 901'
        )->fetchColumn();
        $this->assertSame(1, $orderItemCount, 'Database must contain exactly 1 order item for product #901');
    }

    /**
     * Stress Test: 10 concurrent worker processes racing on stock = 3.
     *
     * Verification Requirements:
     * - Exactly 3 checkouts succeed (201 Created).
     * - Exactly 7 checkouts receive 409 Conflict (RFC 7807).
     * - Stock is exactly 0 and status is outofstock.
     * - Exactly 3 new orders created.
     */
    public function testHighConcurrencyRaceConditionMultipleStockUnits(): void
    {
        $dbPath = $this->env->getDatabasePath();
        $lockPath = $this->env->getLockfilePath();

        // Product 4 has initial stock = 3
        $checkPdo = $this->getFreshPdo();
        $initialStock = (int)$checkPdo->query('SELECT stock_quantity FROM products WHERE id = 4')->fetchColumn();
        $this->assertSame(3, $initialStock, 'Pre-condition: Product 4 must have exactly 3 units in stock');

        $barrierFile = sys_get_temp_dir() . '/wincart_barrier_multi_' . uniqid('', true) . '.lock';
        $barrierFp = fopen($barrierFile, 'w+');
        $this->assertNotFalse($barrierFp);
        flock($barrierFp, LOCK_EX);

        $workerCount = 10;
        $procs = [];
        $outFiles = [];

        for ($i = 0; $i < $workerCount; $i++) {
            $outFile = sys_get_temp_dir() . '/wincart_multi_' . $i . '_' . uniqid('', true) . '.json';
            $outFiles[] = $outFile;
            $payload = json_encode([
                'cart_token' => 'cart_multi_worker_' . $i . '_' . uniqid('', true),
                'items' => [['product_id' => 4, 'quantity' => 1]],
                'payment_token' => 'tok_mock_success',
            ]);

            $cmd = sprintf(
                'php %s --db=%s --lock=%s --barrier=%s --payload=%s --out=%s',
                escapeshellarg($this->workerScript),
                escapeshellarg($dbPath),
                escapeshellarg($lockPath),
                escapeshellarg($barrierFile),
                escapeshellarg($payload),
                escapeshellarg($outFile)
            );

            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open($cmd, $descriptors, $pipes);
            $this->assertIsResource($proc);
            $procs[] = [$proc, $pipes];
        }

        usleep(300000);
        flock($barrierFp, LOCK_UN);
        fclose($barrierFp);

        foreach ($procs as [$proc, $pipes]) {
            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            proc_close($proc);
        }

        $successCount = 0;
        $conflictCount = 0;

        foreach ($outFiles as $outFile) {
            $this->assertFileExists($outFile);
            $data = json_decode((string)file_get_contents($outFile), true);
            $code = (int)($data['status_code'] ?? 0);

            if ($code === 201) {
                $successCount++;
            } elseif ($code === 409) {
                $conflictCount++;
                $this->assertSame('Conflict', $data['json']['title']);
                $this->assertSame(409, $data['json']['status']);
            }
            @unlink($outFile);
        }
        @unlink($barrierFile);

        $this->assertSame(3, $successCount, 'Exactly 3 checkouts must succeed for stock=3');
        $this->assertSame(7, $conflictCount, 'Exactly 7 checkouts must be rejected with 409 Conflict');

        $freshPdo = $this->getFreshPdo();
        $finalStockRow = $freshPdo->query('SELECT stock_quantity, stock_status FROM products WHERE id = 4')->fetch();
        $this->assertSame(0, (int)$finalStockRow['stock_quantity'], 'Final stock must be exactly 0');
        $this->assertSame('outofstock', $finalStockRow['stock_status']);

        $newOrders = (int)$freshPdo->query(
            'SELECT count(*) FROM orders WHERE id NOT IN (1,2,3,4,2001,3001,5001)'
        )->fetchColumn();
        $this->assertSame(3, $newOrders, 'Exactly 3 new orders must be created');
    }

    // =========================================================================
    // 2. Multi-Item Atomic Rollback Testing
    // =========================================================================

    /**
     * Test multi-item checkout where Item A has ample stock (10), Item B has
     * insufficient stock (1 available, 2 requested), and Item C has ample stock (5).
     *
     * Verification Requirements:
     * - Returns 409 Conflict with RFC 7807 Problem Details.
     * - Entire transaction rolls back atomically: 0 items deducted from ANY product.
     * - Item A remains 10. Item B remains 1. Item C remains 5.
     * - Zero new orders created.
     */
    public function testMultiItemAtomicRollbackWhenOneItemHasInsufficientStock(): void
    {
        $pdo = $this->getFreshPdo();
        $stock1Before = (int)$pdo->query('SELECT stock_quantity FROM products WHERE id = 1')->fetchColumn();
        $stock901Before = (int)$pdo->query('SELECT stock_quantity FROM products WHERE id = 901')->fetchColumn();
        $stock2Before = (int)$pdo->query('SELECT stock_quantity FROM products WHERE id = 2')->fetchColumn();

        $this->assertSame(10, $stock1Before);
        $this->assertSame(1, $stock901Before);
        $this->assertSame(5, $stock2Before);

        $response = $this->http->post('/api/v1/checkout', [
            'cart_token' => 'cart_multi_rollback_test',
            'items' => [
                ['product_id' => 1, 'quantity' => 2],   // 2 of 10 -> would leave 8
                ['product_id' => 901, 'quantity' => 2], // 2 of 1 -> DEFICIT! Throws!
                ['product_id' => 2, 'quantity' => 1],   // 1 of 5 -> would leave 4
            ],
            'payment_token' => 'tok_mock_success',
        ]);

        $this->assertProblemDetails($response, 409, 'Conflict');
        $json = $response->getJson();
        $this->assertStringContainsString('Insufficient stock for product #901', $json['detail'] ?? '');
        $this->assertStringContainsString('requested 2 units, 1 available', $json['detail'] ?? '');

        // Verify zero items deducted across ALL products
        $freshPdo = $this->getFreshPdo();
        $stock1After = (int)$freshPdo->query('SELECT stock_quantity FROM products WHERE id = 1')->fetchColumn();
        $stock901After = (int)$freshPdo->query('SELECT stock_quantity FROM products WHERE id = 901')->fetchColumn();
        $stock2After = (int)$freshPdo->query('SELECT stock_quantity FROM products WHERE id = 2')->fetchColumn();

        $this->assertSame(10, $stock1After, 'Product 1 stock must remain completely untouched (10)');
        $this->assertSame(1, $stock901After, 'Product 901 stock must remain completely untouched (1)');
        $this->assertSame(5, $stock2After, 'Product 2 stock must remain completely untouched (5)');

        // Verify zero orders created
        $orderCount = (int)$freshPdo->query(
            'SELECT count(*) FROM orders WHERE id NOT IN (1,2,3,4,2001,3001,5001)'
        )->fetchColumn();
        $this->assertSame(0, $orderCount, 'Zero orders must be created on atomic rollback');
    }

    /**
     * Test multi-item checkout where all stock is available, but payment gateway fails.
     *
     * Verification Requirements:
     * - Returns 422 Payment Failed.
     * - Inventory deducted in Step 3 is rolled back completely.
     * - Stock quantities remain at pre-purchase levels.
     * - Zero orders created.
     */
    public function testMultiItemAtomicRollbackOnPaymentFailure(): void
    {
        $pdo = $this->getFreshPdo();
        $stock1Before = (int)$pdo->query('SELECT stock_quantity FROM products WHERE id = 1')->fetchColumn();
        $stock2Before = (int)$pdo->query('SELECT stock_quantity FROM products WHERE id = 2')->fetchColumn();

        $response = $this->http->post('/api/v1/checkout', [
            'cart_token' => 'cart_pay_fail_test',
            'items' => [
                ['product_id' => 1, 'quantity' => 3],
                ['product_id' => 2, 'quantity' => 2],
            ],
            'payment_token' => 'tok_mock_decline', // Deterministic mock payment decline
        ]);

        $this->assertProblemDetails($response, 422, 'Payment Failed');
        $json = $response->getJson();
        $this->assertStringContainsString('Card declined', $json['detail'] ?? '');

        // Verify stock rollback
        $freshPdo = $this->getFreshPdo();
        $stock1After = (int)$freshPdo->query('SELECT stock_quantity FROM products WHERE id = 1')->fetchColumn();
        $stock2After = (int)$freshPdo->query('SELECT stock_quantity FROM products WHERE id = 2')->fetchColumn();

        $this->assertSame($stock1Before, $stock1After, 'Product 1 stock must be restored to baseline');
        $this->assertSame($stock2Before, $stock2After, 'Product 2 stock must be restored to baseline');

        $orderCount = (int)$freshPdo->query(
            'SELECT count(*) FROM orders WHERE id NOT IN (1,2,3,4,2001,3001,5001)'
        )->fetchColumn();
        $this->assertSame(0, $orderCount, 'Zero orders must be created when payment fails');
    }

    /**
     * Test that when checkout fails due to stock deficit, existing persistent cart
     * items are NOT deleted, preserving customer cart contents.
     */
    public function testMultiItemAtomicRollbackPreservesCartItems(): void
    {
        // 1. Initialize cart
        $cartInit = $this->http->post('/api/v1/cart');
        $cartData = $this->assertJsonResponse($cartInit, 201);
        $cartToken = $cartData['cart_token'];

        // 2. Add Item 1 (prod 1, qty 2)
        $add1 = $this->http->post('/api/v1/cart/items', [
            'product_id' => 1,
            'quantity' => 2,
        ], ['Cookie' => 'wincart_session=' . $cartToken]);
        $this->assertSame(201, $add1->getStatusCode());

        // 3. Add Item 2 (prod 4, qty 1)
        $add2 = $this->http->post('/api/v1/cart/items', [
            'product_id' => 4,
            'quantity' => 1,
        ], ['Cookie' => 'wincart_session=' . $cartToken]);
        $this->assertSame(201, $add2->getStatusCode());

        // 4. Force stock deficit on Product 4 in database
        $pdo = $this->getFreshPdo();
        $pdo->exec("UPDATE products SET stock_quantity = 0, stock_status = 'outofstock' WHERE id = 4");

        // 5. Submit checkout using cart
        $response = $this->http->post('/api/v1/checkout', [
            'cart_token' => $cartToken,
            'payment_token' => 'tok_mock_success',
        ], ['Cookie' => 'wincart_session=' . $cartToken]);

        $this->assertProblemDetails($response, 409, 'Conflict');

        // 6. Verify cart items still exist in cart_items table
        $cartId = (int)$pdo->query("SELECT id FROM carts WHERE token = '{$cartToken}'")->fetchColumn();
        $itemCount = (int)$pdo->query("SELECT count(*) FROM cart_items WHERE cart_id = {$cartId}")->fetchColumn();
        $this->assertSame(2, $itemCount, 'Cart items must not be purged when checkout fails');
    }

    // =========================================================================
    // 3. Restock Correctness Testing
    // =========================================================================

    /**
     * Test order cancellation with restock: true restores catalog quantities.
     */
    public function testRestockOnOrderCancellationWithRestockTrue(): void
    {
        $pdo = $this->getFreshPdo();
        $stockBefore = (int)$pdo->query('SELECT stock_quantity FROM products WHERE id = 1')->fetchColumn();
        $this->assertSame(10, $stockBefore);

        // Place order for 3 units
        $checkoutRes = $this->http->post('/api/v1/checkout', [
            'cart_token' => 'cart_restock_cancel_true',
            'items' => [['product_id' => 1, 'quantity' => 3]],
            'payment_token' => 'tok_mock_success',
        ]);
        $orderData = $this->assertJsonResponse($checkoutRes, 201);
        $orderId = $orderData['id'];

        $stockMid = (int)$pdo->query('SELECT stock_quantity FROM products WHERE id = 1')->fetchColumn();
        $this->assertSame(7, $stockMid, 'Stock must deduct to 7 after order');

        // Cancel order with restock: true
        $cancelRes = $this->http->withToken('pat_admin_scope_token')->post("/api/v1/orders/{$orderId}/cancel", [
            'restock' => true,
            'reason' => 'Customer requested cancellation',
        ]);
        $this->assertSame(200, $cancelRes->getStatusCode());
        $cancelData = $cancelRes->getJson();
        $this->assertSame('cancelled', $cancelData['status']);

        // Verify stock is restored to pre-purchase level (10)
        $freshPdo = $this->getFreshPdo();
        $stockAfter = (int)$freshPdo->query('SELECT stock_quantity FROM products WHERE id = 1')->fetchColumn();
        $this->assertSame(10, $stockAfter, 'Stock must be restored to 10 on cancellation with restock=true');
    }

    /**
     * Test order cancellation with restock: false leaves catalog quantities unchanged.
     */
    public function testRestockOnOrderCancellationWithRestockFalse(): void
    {
        $pdo = $this->getFreshPdo();

        // Place order for 3 units
        $checkoutRes = $this->http->post('/api/v1/checkout', [
            'cart_token' => 'cart_restock_cancel_false',
            'items' => [['product_id' => 1, 'quantity' => 3]],
            'payment_token' => 'tok_mock_success',
        ]);
        $orderData = $this->assertJsonResponse($checkoutRes, 201);
        $orderId = $orderData['id'];

        $stockMid = (int)$pdo->query('SELECT stock_quantity FROM products WHERE id = 1')->fetchColumn();
        $this->assertSame(7, $stockMid);

        // Cancel order with restock: false
        $cancelRes = $this->http->withToken('pat_admin_scope_token')->post("/api/v1/orders/{$orderId}/cancel", [
            'restock' => false,
        ]);
        $this->assertSame(200, $cancelRes->getStatusCode());
        $cancelData = $cancelRes->getJson();
        $this->assertSame('cancelled', $cancelData['status']);

        // Verify stock remains deducted (7)
        $freshPdo = $this->getFreshPdo();
        $stockAfter = (int)$freshPdo->query('SELECT stock_quantity FROM products WHERE id = 1')->fetchColumn();
        $this->assertSame(7, $stockAfter, 'Stock must remain at 7 when restock=false');
    }

    /**
     * Test that restocking restores an out-of-stock product back to instock status.
     */
    public function testRestockRestoresOutOfStockProductBackToInStock(): void
    {
        $pdo = $this->getFreshPdo();

        // Buy the only unit of Product 901
        $checkoutRes = $this->http->post('/api/v1/checkout', [
            'cart_token' => 'cart_restock_status_test',
            'items' => [['product_id' => 901, 'quantity' => 1]],
            'payment_token' => 'tok_mock_success',
        ]);
        $orderData = $this->assertJsonResponse($checkoutRes, 201);
        $orderId = $orderData['id'];

        $midRow = $pdo->query('SELECT stock_quantity, stock_status FROM products WHERE id = 901')->fetch();
        $this->assertSame(0, (int)$midRow['stock_quantity']);
        $this->assertSame('outofstock', $midRow['stock_status']);

        // Cancel with restock: true
        $cancelRes = $this->http->withToken('pat_admin_scope_token')->post("/api/v1/orders/{$orderId}/cancel", [
            'restock' => true,
        ]);
        $this->assertSame(200, $cancelRes->getStatusCode());

        // Verify stock restored to 1 and status restored to instock
        $freshPdo = $this->getFreshPdo();
        $finalRow = $freshPdo->query('SELECT stock_quantity, stock_status FROM products WHERE id = 901')->fetch();
        $this->assertSame(1, (int)$finalRow['stock_quantity']);
        $this->assertSame('instock', $finalRow['stock_status'], 'Restock must restore stock_status to instock');
    }

    /**
     * Test order refund with restock: true restores catalog quantities.
     */
    public function testRestockOnOrderRefundWithRestockTrue(): void
    {
        $pdo = $this->getFreshPdo();

        // Order 2x Product 1 and 1x Product 2
        $checkoutRes = $this->http->post('/api/v1/checkout', [
            'cart_token' => 'cart_refund_true_test',
            'items' => [
                ['product_id' => 1, 'quantity' => 2],
                ['product_id' => 2, 'quantity' => 1],
            ],
            'payment_token' => 'tok_mock_success',
        ]);
        $orderData = $this->assertJsonResponse($checkoutRes, 201);
        $orderId = $orderData['id'];

        // FSM transition: processing -> completed -> refunded (or processing -> refunded)
        $refundRes = $this->http->withToken('pat_admin_scope_token')->post("/api/v1/orders/{$orderId}/refund", [
            'restock' => true,
            'reason' => 'Customer return',
        ]);
        $this->assertSame(200, $refundRes->getStatusCode());
        $refundData = $refundRes->getJson();
        $this->assertSame('refunded', $refundData['status']);

        $freshPdo = $this->getFreshPdo();
        $stock1 = (int)$freshPdo->query('SELECT stock_quantity FROM products WHERE id = 1')->fetchColumn();
        $stock2 = (int)$freshPdo->query('SELECT stock_quantity FROM products WHERE id = 2')->fetchColumn();

        $this->assertSame(10, $stock1, 'Product 1 stock must be restored to 10');
        $this->assertSame(5, $stock2, 'Product 2 stock must be restored to 5');
    }

    /**
     * Test order refund with restock: false leaves catalog quantities unchanged.
     */
    public function testRestockOnOrderRefundWithRestockFalse(): void
    {
        $pdo = $this->getFreshPdo();

        $checkoutRes = $this->http->post('/api/v1/checkout', [
            'cart_token' => 'cart_refund_false_test',
            'items' => [['product_id' => 1, 'quantity' => 2]],
            'payment_token' => 'tok_mock_success',
        ]);
        $orderData = $this->assertJsonResponse($checkoutRes, 201);
        $orderId = $orderData['id'];

        $refundRes = $this->http->withToken('pat_admin_scope_token')->post("/api/v1/orders/{$orderId}/refund", [
            'restock' => false,
        ]);
        $this->assertSame(200, $refundRes->getStatusCode());

        $freshPdo = $this->getFreshPdo();
        $stock1 = (int)$freshPdo->query('SELECT stock_quantity FROM products WHERE id = 1')->fetchColumn();
        $this->assertSame(8, $stock1, 'Stock must remain at 8 when refund has restock=false');
    }

    /**
     * Test partial item restock on refund: refund only a subset of order items.
     */
    public function testPartialItemRestockOnRefund(): void
    {
        $pdo = $this->getFreshPdo();

        $checkoutRes = $this->http->post('/api/v1/checkout', [
            'cart_token' => 'cart_partial_refund_test',
            'items' => [['product_id' => 1, 'quantity' => 4]], // 10 -> 6
            'payment_token' => 'tok_mock_success',
        ]);
        $orderData = $this->assertJsonResponse($checkoutRes, 201);
        $orderId = $orderData['id'];

        // Partial refund of 1 unit
        $refundRes = $this->http->withToken('pat_admin_scope_token')->post("/api/v1/orders/{$orderId}/refund", [
            'restock' => true,
            'items' => [['product_id' => 1, 'quantity' => 1]],
        ]);
        $this->assertSame(200, $refundRes->getStatusCode());

        $freshPdo = $this->getFreshPdo();
        $stock1 = (int)$freshPdo->query('SELECT stock_quantity FROM products WHERE id = 1')->fetchColumn();
        $this->assertSame(7, $stock1, 'Partial restock of 1 unit must bring stock to 7 (6 + 1)');
    }

    // =========================================================================
    // 4. Variable Product Variation Stock Isolation Testing
    // =========================================================================

    /**
     * Test variable product with multiple variations:
     * - Purchasing Variation A deducts stock ONLY from Variation A.
     * - Variation B and Variation C stock counts remain completely intact.
     */
    public function testVariableProductVariationStockIsolation(): void
    {
        $pdo = $this->getFreshPdo();

        // Seed third variation 603
        $stmt = $pdo->prepare(
            'INSERT INTO product_variations (
                id, product_id, sku, price, stock_quantity, stock_status, attributes_json,
                is_active, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([603, 600, 'VAR-603', 3000, 5, 'instock', json_encode(['color' => 'blue'])]);

        $v601Before = (int)$pdo->query('SELECT stock_quantity FROM product_variations WHERE id = 601')->fetchColumn();
        $v602Before = (int)$pdo->query('SELECT stock_quantity FROM product_variations WHERE id = 602')->fetchColumn();
        $v603Before = (int)$pdo->query('SELECT stock_quantity FROM product_variations WHERE id = 603')->fetchColumn();

        $this->assertSame(3, $v601Before);
        $this->assertSame(0, $v602Before);
        $this->assertSame(5, $v603Before);

        // Purchase 2 units of Variation 601
        $checkoutRes = $this->http->post('/api/v1/checkout', [
            'cart_token' => 'cart_var_isolation_test',
            'items' => [['product_id' => 600, 'variation_id' => 601, 'quantity' => 2]],
            'payment_token' => 'tok_mock_success',
        ]);
        $this->assertJsonResponse($checkoutRes, 201);

        $freshPdo = $this->getFreshPdo();
        $v601After = (int)$freshPdo->query(
            'SELECT stock_quantity FROM product_variations WHERE id = 601'
        )->fetchColumn();
        $v602After = (int)$freshPdo->query(
            'SELECT stock_quantity FROM product_variations WHERE id = 602'
        )->fetchColumn();
        $v603After = (int)$freshPdo->query(
            'SELECT stock_quantity FROM product_variations WHERE id = 603'
        )->fetchColumn();

        // Variation A deducted; Variations B and C untouched
        $this->assertSame(1, $v601After, 'Variation 601 stock must deduct from 3 to 1');
        $this->assertSame(0, $v602After, 'Variation 602 stock must remain 0 (untouched)');
        $this->assertSame(5, $v603After, 'Variation 603 stock must remain 5 (untouched)');
    }

    /**
     * Test variable product rejecting purchase of out-of-stock variation without
     * affecting available variations.
     */
    public function testVariableProductCannotPurchaseOutOfStockVariation(): void
    {
        $response = $this->http->post('/api/v1/checkout', [
            'cart_token' => 'cart_var_oos_test',
            'items' => [['product_id' => 600, 'variation_id' => 602, 'quantity' => 1]],
            'payment_token' => 'tok_mock_success',
        ]);

        $this->assertProblemDetails($response, 409, 'Conflict');
        $json = $response->getJson();
        $this->assertStringContainsString('Insufficient stock for variation #602', $json['detail'] ?? '');
        $this->assertStringContainsString('0 available', $json['detail'] ?? '');

        // Verify Variation 601 is untouched
        $pdo = $this->getFreshPdo();
        $v601 = (int)$pdo->query('SELECT stock_quantity FROM product_variations WHERE id = 601')->fetchColumn();
        $this->assertSame(3, $v601);
    }

    /**
     * Test variable product multi-variation atomic rollback:
     * When cart contains Variation A (in stock) and Variation B (out of stock),
     * entire checkout rolls back with 0 units deducted from Variation A.
     */
    public function testVariableProductMultiVariationAtomicRollback(): void
    {
        $response = $this->http->post('/api/v1/checkout', [
            'cart_token' => 'cart_var_atomic_test',
            'items' => [
                ['product_id' => 600, 'variation_id' => 601, 'quantity' => 1], // In stock (3)
                ['product_id' => 600, 'variation_id' => 602, 'quantity' => 1], // Out of stock (0) -> FAIL!
            ],
            'payment_token' => 'tok_mock_success',
        ]);

        $this->assertProblemDetails($response, 409, 'Conflict');

        $pdo = $this->getFreshPdo();
        $v601 = (int)$pdo->query('SELECT stock_quantity FROM product_variations WHERE id = 601')->fetchColumn();
        $v602 = (int)$pdo->query('SELECT stock_quantity FROM product_variations WHERE id = 602')->fetchColumn();

        $this->assertSame(3, $v601, 'Variation 601 stock must not be deducted on atomic rollback');
        $this->assertSame(0, $v602);
    }

    /**
     * Test restocking a variable product order restores variation stock accurately.
     */
    public function testVariableProductRestockOnCancellation(): void
    {
        $pdo = $this->getFreshPdo();

        $checkoutRes = $this->http->post('/api/v1/checkout', [
            'cart_token' => 'cart_var_restock_test',
            'items' => [['product_id' => 600, 'variation_id' => 601, 'quantity' => 2]],
            'payment_token' => 'tok_mock_success',
        ]);
        $orderData = $this->assertJsonResponse($checkoutRes, 201);
        $orderId = $orderData['id'];

        $cancelRes = $this->http->withToken('pat_admin_scope_token')->post("/api/v1/orders/{$orderId}/cancel", [
            'restock' => true,
        ]);
        $this->assertSame(200, $cancelRes->getStatusCode());

        $freshPdo = $this->getFreshPdo();
        $v601 = (int)$freshPdo->query('SELECT stock_quantity FROM product_variations WHERE id = 601')->fetchColumn();
        $this->assertSame(3, $v601, 'Variation 601 stock must be restored to 3 on restock=true');
    }

    /**
     * Test concurrent checkouts of two distinct variations simultaneously:
     * Worker 1 purchases Variation 601 (stock=3), Worker 2 purchases Variation 603 (stock=5).
     *
     * Verification Requirements:
     * - Both checkouts succeed with 201 Created.
     * - Neither variation blocks the other.
     * - Variation 601 becomes 2, Variation 603 becomes 4.
     */
    public function testConcurrentPurchasesOfDistinctVariationsDoNotConflict(): void
    {
        $dbPath = $this->env->getDatabasePath();
        $lockPath = $this->env->getLockfilePath();

        $pdo = $this->getFreshPdo();
        $stmt = $pdo->prepare(
            'INSERT INTO product_variations (
                id, product_id, sku, price, stock_quantity, stock_status, attributes_json,
                is_active, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([603, 600, 'VAR-603', 3000, 5, 'instock', json_encode(['color' => 'blue'])]);

        $barrierFile = sys_get_temp_dir() . '/wincart_var_barrier_' . uniqid('', true) . '.lock';
        $barrierFp = fopen($barrierFile, 'w+');
        $this->assertNotFalse($barrierFp);
        flock($barrierFp, LOCK_EX);

        // Worker A targets Variation 601
        $outA = sys_get_temp_dir() . '/wincart_var_a_' . uniqid('', true) . '.json';
        $payloadA = json_encode([
            'cart_token' => 'cart_var_race_a_' . uniqid('', true),
            'items' => [['product_id' => 600, 'variation_id' => 601, 'quantity' => 1]],
            'payment_token' => 'tok_mock_success',
        ]);
        $cmdA = sprintf(
            'php %s --db=%s --lock=%s --barrier=%s --payload=%s --out=%s',
            escapeshellarg($this->workerScript),
            escapeshellarg($dbPath),
            escapeshellarg($lockPath),
            escapeshellarg($barrierFile),
            escapeshellarg($payloadA),
            escapeshellarg($outA)
        );

        // Worker B targets Variation 603
        $outB = sys_get_temp_dir() . '/wincart_var_b_' . uniqid('', true) . '.json';
        $payloadB = json_encode([
            'cart_token' => 'cart_var_race_b_' . uniqid('', true),
            'items' => [['product_id' => 600, 'variation_id' => 603, 'quantity' => 1]],
            'payment_token' => 'tok_mock_success',
        ]);
        $cmdB = sprintf(
            'php %s --db=%s --lock=%s --barrier=%s --payload=%s --out=%s',
            escapeshellarg($this->workerScript),
            escapeshellarg($dbPath),
            escapeshellarg($lockPath),
            escapeshellarg($barrierFile),
            escapeshellarg($payloadB),
            escapeshellarg($outB)
        );

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $procA = proc_open($cmdA, $descriptors, $pipesA);
        $procB = proc_open($cmdB, $descriptors, $pipesB);

        usleep(300000);
        flock($barrierFp, LOCK_UN);
        fclose($barrierFp);

        fclose($pipesA[0]);
        stream_get_contents($pipesA[1]);
        fclose($pipesA[1]);
        fclose($pipesA[2]);
        proc_close($procA);

        fclose($pipesB[0]);
        stream_get_contents($pipesB[1]);
        fclose($pipesB[1]);
        fclose($pipesB[2]);
        proc_close($procB);

        $dataA = json_decode((string)file_get_contents($outA), true);
        $dataB = json_decode((string)file_get_contents($outB), true);
        @unlink($outA);
        @unlink($outB);
        @unlink($barrierFile);

        $this->assertSame(201, $dataA['status_code'], 'Worker A (Variation 601) must succeed with 201');
        $this->assertSame(201, $dataB['status_code'], 'Worker B (Variation 603) must succeed with 201');

        $freshPdo = $this->getFreshPdo();
        $v601 = (int)$freshPdo->query('SELECT stock_quantity FROM product_variations WHERE id = 601')->fetchColumn();
        $v603 = (int)$freshPdo->query('SELECT stock_quantity FROM product_variations WHERE id = 603')->fetchColumn();

        $this->assertSame(2, $v601, 'Variation 601 stock must be 2 (3 - 1)');
        $this->assertSame(4, $v603, 'Variation 603 stock must be 4 (5 - 1)');
    }
}
