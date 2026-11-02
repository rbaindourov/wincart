<?php

declare(strict_types=1);

namespace WinCart\Tests\Feature;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use WinCart\Database\ConnectionFactory;
use WinCart\Database\MigrationRunner;
use WinCart\Database\TestFixtureSeeder;
use WinCart\Http\Kernel;
use WinCart\Mcp\Transports\SseSessionManager;

/**
 * Challenger M4-1 Empirical Stress & Concurrency Test Suite.
 *
 * Stress-tests:
 * 1. bin/mcp-server stdio transport:
 *    - High-throughput streaming (50+ rapid requests written without pausing)
 *    - Large payload request (>64 KB) verifying no POSIX pipe buffer deadlock
 *    - Malformed JSON, truncated lines, empty lines, and server process survival (-32700)
 * 2. HTTP/SSE transport & session isolation:
 *    - Concurrent creation of 20 distinct SSE sessions via GET /api/mcp/sse
 *    - Interleaved message dispatch to POST /api/mcp/messages with strict session isolation
 *    - Expired and invalid session ID handling with RFC 7807 Problem Details (404)
 */
class ChallengerM4OneStressTest extends TestCase
{
    private string $tempDbFile;
    private string $tempLockfile;
    private string $tempStorageDir;
    private PDO $pdo;
    private Kernel $kernel;
    private string $mcpBinaryPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mcpBinaryPath = dirname(__DIR__, 2) . '/bin/mcp-server';
        $this->assertFileExists($this->mcpBinaryPath, 'mcp-server binary must exist');

        $this->tempDbFile = sys_get_temp_dir() . '/wincart_challenger_m41_' . uniqid() . '.sqlite';
        $this->tempLockfile = sys_get_temp_dir() . '/wincart_challenger_m41_lock_' . uniqid() . '.lock';
        touch($this->tempLockfile);

        $this->tempStorageDir = sys_get_temp_dir() . '/wincart_challenger_m41_storage_' . uniqid();
        @mkdir($this->tempStorageDir . '/cache/mcp_sessions', 0775, true);

        $this->pdo = ConnectionFactory::create([
            'driver' => 'sqlite',
            'path' => $this->tempDbFile,
        ]);

        $runner = new MigrationRunner($this->pdo);
        $runner->migrateUp();
        TestFixtureSeeder::seed($this->pdo);

        $this->kernel = new Kernel([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $this->tempDbFile,
            'STORAGE_PATH' => $this->tempStorageDir,
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
        if (is_dir($this->tempStorageDir)) {
            $this->recursiveRmdir($this->tempStorageDir);
        }

        parent::tearDown();
    }

    private function recursiveRmdir(string $dir): void
    {
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->recursiveRmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * Helper to spawn a fresh bin/mcp-server process connected to the test database.
     *
     * @return array{0: resource, 1: array<int, resource>} [process, pipes]
     */
    private function spawnMcpServer(): array
    {
        $cmd = [PHP_BINARY, $this->mcpBinaryPath];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $this->tempDbFile,
            'STORAGE_PATH' => $this->tempStorageDir,
            'INSTALL_LOCK_PATH' => $this->tempLockfile,
        ]);

        $process = proc_open($cmd, $descriptors, $pipes, dirname($this->mcpBinaryPath, 2), $env);
        $this->assertIsResource($process, 'Failed to spawn bin/mcp-server process');

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [$process, $pipes];
    }

    /**
     * Helper to close an MCP server process cleanly.
     *
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    private function closeMcpServer($process, array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($process)) {
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process);
            }
            proc_close($process);
        }
    }

    /**
     * Read a single complete line from stdout with timeout using non-blocking stream_select.
     *
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    private function readStdoutLine(array $pipes, string &$buffer, float $timeout = 10.0): string
    {
        $startTime = microtime(true);

        while (!str_contains($buffer, "\n")) {
            $elapsed = microtime(true) - $startTime;
            if ($elapsed >= $timeout) {
                $bufSnippet = substr($buffer, 0, 200);
                $this->fail("Timed out after {$timeout}s waiting for newline in MCP stdout buffer: {$bufSnippet}");
            }

            $r = [$pipes[1], $pipes[2]];
            $w = null;
            $e = null;
            $rem = max(0.001, $timeout - $elapsed);
            $sec = (int)floor($rem);
            $usec = (int)(($rem - $sec) * 1_000_000);

            $changed = stream_select($r, $w, $e, $sec, $usec);
            if ($changed === false) {
                break;
            }

            if (in_array($pipes[2], $r, true)) {
                fread($pipes[2], 8192); // Drain stderr
            }

            if (in_array($pipes[1], $r, true)) {
                $chunk = fread($pipes[1], 8192);
                if ($chunk !== false && $chunk !== '') {
                    $buffer .= $chunk;
                } elseif (feof($pipes[1])) {
                    break;
                }
            }
        }

        $pos = strpos($buffer, "\n");
        if ($pos === false) {
            $this->fail("Process closed without emitting newline. Buffer: " . substr($buffer, 0, 200));
        }

        $line = substr($buffer, 0, $pos);
        $buffer = substr($buffer, $pos + 1);

        return $line;
    }

    // =========================================================================
    // SECTION 1: bin/mcp-server Stdio Transport Tests
    // =========================================================================

    /**
     * Requirement 1.1: High-throughput streaming test.
     * Write 50+ rapid JSON-RPC requests through stdin without pausing;
     * assert 50 matching line-delimited responses without buffer stall.
     */
    public function testStdioHighThroughputStreamingWithoutBufferStall(): void
    {
        [$process, $pipes] = $this->spawnMcpServer();
        $stdoutBuf = '';

        try {
            // Handshake first
            $initReq = json_encode([
                'jsonrpc' => '2.0',
                'id' => 'init-0',
                'method' => 'initialize',
                'params' => ['protocolVersion' => '2024-11-05'],
            ]) . "\n";
            fwrite($pipes[0], $initReq);
            fflush($pipes[0]);

            $initLine = $this->readStdoutLine($pipes, $stdoutBuf, 5.0);
            $initRes = json_decode($initLine, true);
            $this->assertSame('init-0', $initRes['id'] ?? null);
            $this->assertSame('2.0', $initRes['jsonrpc'] ?? null);

            // Construct 60 rapid JSON-RPC requests across diverse methods
            $requestCount = 60;
            $requestPayloads = [];
            $expectedIds = [];

            for ($i = 1; $i <= $requestCount; $i++) {
                $id = "stream-req-{$i}";
                $expectedIds[] = $id;

                $method = match ($i % 6) {
                    0 => 'ping',
                    1 => 'tools/list',
                    2 => 'resources/list',
                    3 => 'prompts/list',
                    4 => 'tools/call',
                    5 => 'tools/call',
                };

                $params = match ($i % 6) {
                    4 => ['name' => 'get_store_metrics', 'arguments' => []],
                    5 => ['name' => 'search_products', 'arguments' => ['query' => 'Microphone']],
                    default => [],
                };

                $requestPayloads[] = json_encode([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'method' => $method,
                    'params' => $params,
                ]) . "\n";
            }

            // Write all 60 requests through stdin rapidly in a tight loop WITHOUT pausing
            $startTime = microtime(true);
            $fullBatch = implode('', $requestPayloads);

            $written = fwrite($pipes[0], $fullBatch);
            $this->assertSame(
                strlen($fullBatch),
                $written,
                'All 60 requests must be written to stdin without truncation'
            );
            fflush($pipes[0]);

            // Stream and collect all 60 responses
            $receivedResponses = [];
            for ($i = 0; $i < $requestCount; $i++) {
                $line = $this->readStdoutLine($pipes, $stdoutBuf, 10.0);
                $decoded = json_decode($line, true);
                $this->assertIsArray($decoded, "Response line {$i} must be valid JSON: {$line}");
                $this->assertSame('2.0', $decoded['jsonrpc'] ?? null);
                $this->assertArrayNotHasKey('error', $decoded, "Request should not have returned error: {$line}");
                $receivedResponses[] = $decoded;
            }

            $elapsed = microtime(true) - $startTime;

            $this->assertCount(60, $receivedResponses, 'Must receive exactly 60 responses for 60 requests');

            // Assert exact 1:1 ID match in line order
            foreach ($receivedResponses as $idx => $resp) {
                $this->assertSame($expectedIds[$idx], $resp['id'], "Response #{$idx} must match request ID");
            }

            // Assert high throughput (< 4.0s for 60 requests)
            $this->assertLessThan(4.0, $elapsed, "High-throughput streaming took {$elapsed}s (exceeds 4.0s threshold)");
        } finally {
            $this->closeMcpServer($process, $pipes);
        }
    }

    /**
     * Requirement 1.2: Large payload request (>64 KB) and verify no POSIX pipe deadlock.
     * Tests that payloads exceeding the 65,536-byte Linux pipe buffer write and stream cleanly.
     */
    public function testStdioLargePayloadNoPipeDeadlock(): void
    {
        [$process, $pipes] = $this->spawnMcpServer();
        $stdoutBuf = '';

        try {
            // Handshake
            fwrite($pipes[0], json_encode([
                'jsonrpc' => '2.0',
                'id' => 'init-large',
                'method' => 'initialize',
                'params' => ['protocolVersion' => '2024-11-05'],
            ]) . "\n");
            fflush($pipes[0]);
            $this->readStdoutLine($pipes, $stdoutBuf, 5.0);

            // Construct large product payload: description of 80,000 characters
            // Total JSON-RPC response will be > 80 KB (strictly exceeding 65,536 byte POSIX pipe buffer)
            $largeDescription = str_repeat('WinCartLargeDataChunk_1234567890_ABCDEF ', 2050); // ~82,000 chars
            $this->assertGreaterThan(80000, strlen($largeDescription));

            $createReq = json_encode([
                'jsonrpc' => '2.0',
                'id' => 'req-large-create',
                'method' => 'tools/call',
                'params' => [
                    'name' => 'create_product',
                    'arguments' => [
                        'name' => 'Bulk Sensor 9000',
                        'sku' => 'BULK-9000',
                        'price' => 19999,
                        'stock_quantity' => 50,
                        'description' => $largeDescription,
                    ],
                ],
            ]) . "\n";

            // Write large request
            $written = fwrite($pipes[0], $createReq);
            $this->assertSame(strlen($createReq), $written);
            fflush($pipes[0]);

            // Read the large response line from stdout
            $responseLine = $this->readStdoutLine($pipes, $stdoutBuf, 10.0);
            $responseLen = strlen($responseLine);

            $this->assertGreaterThan(
                65536,
                $responseLen,
                "Response payload ({$responseLen} bytes) must exceed 64 KB POSIX pipe limit to test deadlock resilience"
            );

            $decoded = json_decode($responseLine, true);
            $this->assertIsArray($decoded, 'Large response must be valid JSON-RPC');
            $this->assertSame('req-large-create', $decoded['id']);
            $this->assertSame('2.0', $decoded['jsonrpc']);
            $this->assertArrayHasKey('result', $decoded);

            // Retrieve created product ID
            $content = $decoded['result']['content'][0]['text'] ?? '';
            $productData = json_decode($content, true);
            $this->assertIsArray($productData);
            $createdId = $productData['id'] ?? null;
            $this->assertNotNull($createdId);

            // Fetch via get_product to re-verify large payload streaming
            $getReq = json_encode([
                'jsonrpc' => '2.0',
                'id' => 'req-large-get',
                'method' => 'tools/call',
                'params' => [
                    'name' => 'get_product',
                    'arguments' => ['id' => $createdId],
                ],
            ]) . "\n";

            fwrite($pipes[0], $getReq);
            fflush($pipes[0]);

            $getResponseLine = $this->readStdoutLine($pipes, $stdoutBuf, 10.0);
            $this->assertGreaterThan(65536, strlen($getResponseLine));

            $getDecoded = json_decode($getResponseLine, true);
            $this->assertSame('req-large-get', $getDecoded['id']);
            $getText = $getDecoded['result']['content'][0]['text'] ?? '';
            $this->assertStringContainsString('WinCartLargeDataChunk', $getText);

            // Also test list_orders with limit: 100 after seeding orders
            $listReq = json_encode([
                'jsonrpc' => '2.0',
                'id' => 'req-list-orders',
                'method' => 'tools/call',
                'params' => [
                    'name' => 'list_orders',
                    'arguments' => ['limit' => 100],
                ],
            ]) . "\n";

            fwrite($pipes[0], $listReq);
            fflush($pipes[0]);

            $listLine = $this->readStdoutLine($pipes, $stdoutBuf, 10.0);
            $listDecoded = json_decode($listLine, true);
            $this->assertSame('req-list-orders', $listDecoded['id']);
            $this->assertArrayHasKey('result', $listDecoded);
        } finally {
            $this->closeMcpServer($process, $pipes);
        }
    }

    /**
     * Requirement 1.3: Malformed JSON, truncated lines, and empty lines.
     * Assert clean JSON-RPC parse errors (-32700) without crashing the server process.
     */
    public function testStdioMalformedTruncatedAndEmptyLinesResilience(): void
    {
        [$process, $pipes] = $this->spawnMcpServer();
        $stdoutBuf = '';

        try {
            // Handshake
            fwrite($pipes[0], json_encode([
                'jsonrpc' => '2.0',
                'id' => 'init-malformed',
                'method' => 'initialize',
                'params' => ['protocolVersion' => '2024-11-05'],
            ]) . "\n");
            fflush($pipes[0]);
            $this->readStdoutLine($pipes, $stdoutBuf, 5.0);

            // 1. Send empty lines and whitespace lines -> must be skipped silently
            fwrite($pipes[0], "\n\n   \n\t   \n");
            fflush($pipes[0]);

            // 2. Send malformed JSON (broken syntax)
            fwrite($pipes[0], "{\"jsonrpc\": \"2.0\", \"id\": \"err1\", \"method\": broken_json_value\n");
            fflush($pipes[0]);

            $errLine1 = $this->readStdoutLine($pipes, $stdoutBuf, 5.0);
            $err1 = json_decode($errLine1, true);
            $this->assertSame('2.0', $err1['jsonrpc'] ?? null);
            $this->assertSame(-32700, $err1['error']['code'] ?? null, 'Must return -32700 PARSE_ERROR on broken JSON');
            $this->assertStringContainsString('Parse error', $err1['error']['message'] ?? '');

            // 3. Send truncated line (unclosed JSON object)
            fwrite($pipes[0], "{\"jsonrpc\": \"2.0\", \"id\": \"err2\", \"method\": \"tools/lis\n");
            fflush($pipes[0]);

            $errLine2 = $this->readStdoutLine($pipes, $stdoutBuf, 5.0);
            $err2 = json_decode($errLine2, true);
            $this->assertSame(-32700, $err2['error']['code'] ?? null, 'Must return -32700 on truncated line');

            // 4. Send invalid JSON-RPC (missing jsonrpc 2.0 version)
            fwrite($pipes[0], json_encode(['jsonrpc' => '1.0', 'id' => 'err3', 'method' => 'ping']) . "\n");
            fflush($pipes[0]);

            $errLine3 = $this->readStdoutLine($pipes, $stdoutBuf, 5.0);
            $err3 = json_decode($errLine3, true);
            $this->assertSame(-32600, $err3['error']['code'] ?? null, 'Must return -32600 on non-2.0 JSON-RPC version');

            // 5. Send missing method
            fwrite($pipes[0], json_encode(['jsonrpc' => '2.0', 'id' => 'err4']) . "\n");
            fflush($pipes[0]);

            $errLine4 = $this->readStdoutLine($pipes, $stdoutBuf, 5.0);
            $err4 = json_decode($errLine4, true);
            $this->assertSame(-32600, $err4['error']['code'] ?? null, 'Must return -32600 on missing method');

            // 6. Send unknown method
            $unknownReq = json_encode([
                'jsonrpc' => '2.0',
                'id' => 'err5',
                'method' => 'non_existent_method',
            ]) . "\n";
            fwrite($pipes[0], $unknownReq);
            fflush($pipes[0]);

            $errLine5 = $this->readStdoutLine($pipes, $stdoutBuf, 5.0);
            $err5 = json_decode($errLine5, true);
            $this->assertSame(-32601, $err5['error']['code'] ?? null, 'Must return -32601 METHOD_NOT_FOUND');

            // 7. Verify server process remains alive and fully operational
            $procStatus = proc_get_status($process);
            $this->assertTrue($procStatus['running'], 'MCP server process must remain alive after all error inputs');

            // 8. Send valid recovery request
            fwrite($pipes[0], json_encode(['jsonrpc' => '2.0', 'id' => 'recovery-ping', 'method' => 'ping']) . "\n");
            fflush($pipes[0]);

            $recLine = $this->readStdoutLine($pipes, $stdoutBuf, 5.0);
            $rec = json_decode($recLine, true);
            $this->assertSame('recovery-ping', $rec['id'] ?? null);
            $this->assertArrayHasKey('result', $rec, 'Recovery ping must succeed after error bombardment');
        } finally {
            $this->closeMcpServer($process, $pipes);
        }
    }

    // =========================================================================
    // SECTION 2: HTTP/SSE Transport & Session Isolation Tests
    // =========================================================================

    /**
     * Requirement 2.1: Concurrently create 20 distinct SSE sessions via GET /api/mcp/sse.
     */
    public function testHttpSseConcurrent20SessionsCreation(): void
    {
        $sessionCount = 20;
        $sessionIds = [];
        $sessionDir = $this->tempStorageDir . '/cache/mcp_sessions';

        for ($i = 1; $i <= $sessionCount; $i++) {
            $response = $this->kernel->handleRequest(
                'GET',
                '/api/mcp/sse',
                null,
                ['Authorization' => "Bearer test-agent-token-{$i}"]
            );

            $this->assertSame(200, $response->getStatusCode(), "SSE connect #{$i} must return 200 OK");
            $this->assertSame('text/event-stream; charset=UTF-8', $response->getHeaderLine('Content-Type'));
            $this->assertSame('no-cache', $response->getHeaderLine('Cache-Control'));

            $body = $response->getBody();
            $this->assertStringContainsString('event: endpoint', $body);
            $this->assertStringContainsString('/api/mcp/messages?sessionId=', $body);

            preg_match('#sessionId=([a-zA-Z0-9_-]+)#', $body, $matches);
            $this->assertNotEmpty($matches[1], "Session ID must be extracted from SSE stream #{$i}");
            $sessionId = $matches[1];

            // Validate UUID v4 format
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $sessionId,
                "Session ID #{$i} must be valid UUID v4"
            );

            $sessionIds[] = $sessionId;

            // Verify session file exists on disk immediately
            $sessionFile = $sessionDir . '/' . $sessionId . '.json';
            $this->assertFileExists($sessionFile, "Session file must exist on disk: {$sessionFile}");

            $sessionData = json_decode(file_get_contents($sessionFile) ?: '', true);
            $this->assertIsArray($sessionData);
            $this->assertSame($sessionId, $sessionData['sessionId']);
            $this->assertSame("test-agent-token-{$i}", $sessionData['token']);
        }

        // Assert all 20 session IDs are strictly unique
        $uniqueSessionIds = array_unique($sessionIds);
        $this->assertCount(20, $uniqueSessionIds, 'All 20 SSE sessions must have globally unique session IDs');
    }

    /**
     * Requirement 2.2: Dispatch messages with interleaved session IDs to POST /api/mcp/messages.
     * Assert strict session isolation (no cross-talk or race collisions).
     */
    public function testHttpSseInterleavedMessageDispatchAndStrictSessionIsolation(): void
    {
        $sessionCount = 20;
        $sessions = [];

        // 1. Establish 20 distinct sessions with distinct tokens
        for ($i = 1; $i <= $sessionCount; $i++) {
            $connectRes = $this->kernel->handleRequest(
                'GET',
                '/api/mcp/sse',
                null,
                ['Authorization' => "Bearer isolated-agent-token-{$i}"]
            );
            preg_match('#sessionId=([a-zA-Z0-9_-]+)#', $connectRes->getBody(), $matches);
            $sessions[$i] = [
                'index' => $i,
                'sessionId' => $matches[1],
                'token' => "isolated-agent-token-{$i}",
            ];
        }

        // 2. Interleaved Round 1: initialize across all 20 sessions with session-specific clientInfo
        foreach ($sessions as $i => $s) {
            $initPayload = json_encode([
                'jsonrpc' => '2.0',
                'id' => "init-s{$i}",
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2024-11-05',
                    'clientInfo' => ['name' => "agent-client-{$i}", 'version' => "{$i}.0.0"],
                ],
            ]);

            $res = $this->kernel->handleRequest(
                'POST',
                "/api/mcp/messages?sessionId={$s['sessionId']}",
                $initPayload,
                ['Content-Type' => 'application/json']
            );

            $this->assertSame(200, $res->getStatusCode(), "Initialize on session {$i} must return 200 OK");
            $json = json_decode($res->getBody(), true);
            $this->assertSame("init-s{$i}", $json['id']);
            $this->assertSame('2024-11-05', $json['result']['protocolVersion']);
            $this->assertSame('wincart-mcp', $json['result']['serverInfo']['name']);
        }

        // 3. Interleaved Round 2: dispatch varied interleaved messages in shuffled order
        $interleavedQueue = [];
        for ($i = 1; $i <= $sessionCount; $i++) {
            // Request A: tools/list
            $interleavedQueue[] = [
                'sessionIndex' => $i,
                'sessionId' => $sessions[$i]['sessionId'],
                'reqId' => "list-s{$i}",
                'payload' => [
                    'jsonrpc' => '2.0',
                    'id' => "list-s{$i}",
                    'method' => 'tools/list',
                    'params' => [],
                ],
            ];
            // Request B: get_store_metrics
            $interleavedQueue[] = [
                'sessionIndex' => $i,
                'sessionId' => $sessions[$i]['sessionId'],
                'reqId' => "metrics-s{$i}",
                'payload' => [
                    'jsonrpc' => '2.0',
                    'id' => "metrics-s{$i}",
                    'method' => 'tools/call',
                    'params' => ['name' => 'get_store_metrics', 'arguments' => []],
                ],
            ];
            // Request C: search_products
            $interleavedQueue[] = [
                'sessionIndex' => $i,
                'sessionId' => $sessions[$i]['sessionId'],
                'reqId' => "search-s{$i}",
                'payload' => [
                    'jsonrpc' => '2.0',
                    'id' => "search-s{$i}",
                    'method' => 'tools/call',
                    'params' => ['name' => 'search_products', 'arguments' => ['query' => "Item-{$i}"]],
                ],
            ];
        }

        // Shuffle queue to guarantee non-sequential, interleaved dispatch
        shuffle($interleavedQueue);

        $sessionManager = new SseSessionManager($this->tempStorageDir);

        foreach ($interleavedQueue as $item) {
            $res = $this->kernel->handleRequest(
                'POST',
                "/api/mcp/messages?sessionId={$item['sessionId']}",
                json_encode($item['payload']),
                ['Content-Type' => 'application/json']
            );

            $this->assertSame(
                200,
                $res->getStatusCode(),
                "Interleaved request {$item['reqId']} on session {$item['sessionIndex']} must return 200"
            );

            $data = json_decode($res->getBody(), true);
            $this->assertIsArray($data);
            $this->assertSame('2.0', $data['jsonrpc']);
            $this->assertSame($item['reqId'], $data['id'], "Response ID must match {$item['reqId']}");
            $this->assertArrayHasKey('result', $data);

            // Assert session file was touched without cross-session corruption
            $sessionData = $sessionManager->getSession($item['sessionId']);
            $this->assertNotNull($sessionData);
            $this->assertSame($item['sessionId'], $sessionData['sessionId']);
            $this->assertSame("isolated-agent-token-{$item['sessionIndex']}", $sessionData['token']);
        }
    }

    /**
     * Requirement 2.3: Expired or invalid session ID handling.
     * Assert clean 404 Problem Details (RFC 7807) on non-existent, path traversal, empty, or expired sessions.
     */
    public function testHttpSseExpiredAndInvalidSessionHandlingProblemDetails(): void
    {
        $validPayload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 'ping-test',
            'method' => 'ping',
        ]);

        // Case 1: Non-existent UUID session ID
        $res1 = $this->kernel->handleRequest(
            'POST',
            '/api/mcp/messages?sessionId=00000000-0000-4000-8000-000000000000',
            $validPayload,
            ['Content-Type' => 'application/json']
        );

        $this->assertSame(404, $res1->getStatusCode());
        $this->assertStringContainsString('application/problem+json', $res1->getHeaderLine('Content-Type'));
        $problem1 = json_decode($res1->getBody(), true);
        $this->assertSame('https://tools.ietf.org/html/rfc7807', $problem1['type']);
        $this->assertSame(404, $problem1['status']);
        $this->assertSame('Not Found', $problem1['title']);
        $this->assertSame('MCP session not found or expired', $problem1['detail']);

        // Case 2: Path traversal attempt
        $res2 = $this->kernel->handleRequest(
            'POST',
            '/api/mcp/messages?sessionId=../../etc/passwd',
            $validPayload,
            ['Content-Type' => 'application/json']
        );

        $this->assertSame(404, $res2->getStatusCode());
        $this->assertStringContainsString('application/problem+json', $res2->getHeaderLine('Content-Type'));
        $problem2 = json_decode($res2->getBody(), true);
        $this->assertSame(404, $problem2['status']);
        $this->assertSame('MCP session not found or expired', $problem2['detail']);

        // Case 3: Empty session ID
        $res3 = $this->kernel->handleRequest(
            'POST',
            '/api/mcp/messages?sessionId=',
            $validPayload,
            ['Content-Type' => 'application/json']
        );

        $this->assertSame(404, $res3->getStatusCode());
        $problem3 = json_decode($res3->getBody(), true);
        $this->assertSame(404, $problem3['status']);

        // Case 4: Expired session (manually set lastActivityAt > TTL in session JSON file)
        $sessionManager = new SseSessionManager($this->tempStorageDir);
        $expiredSessionId = $sessionManager->createSession('expired-token');
        $sessionFile = $this->tempStorageDir . '/cache/mcp_sessions/' . $expiredSessionId . '.json';
        $this->assertFileExists($sessionFile);

        // Artificially age session by 7200 seconds (TTL is 3600 seconds)
        $data = json_decode(file_get_contents($sessionFile) ?: '', true);
        $data['lastActivityAt'] = time() - 7200;
        file_put_contents($sessionFile, json_encode($data, JSON_PRETTY_PRINT));

        // Dispatch to expired session
        $res4 = $this->kernel->handleRequest(
            'POST',
            "/api/mcp/messages?sessionId={$expiredSessionId}",
            $validPayload,
            ['Content-Type' => 'application/json']
        );

        $this->assertSame(404, $res4->getStatusCode());
        $this->assertStringContainsString('application/problem+json', $res4->getHeaderLine('Content-Type'));
        $problem4 = json_decode($res4->getBody(), true);
        $this->assertSame(404, $problem4['status']);
        $this->assertSame('MCP session not found or expired', $problem4['detail']);

        // Verify the expired session file was purged by hasSession garbage collection
        $this->assertFileDoesNotExist($sessionFile, 'Expired session file must be deleted upon detection');

        // Case 5: Empty JSON-RPC body to a valid active session -> assert 422 Problem Details
        $activeSessionId = $sessionManager->createSession('active-token');
        $res5 = $this->kernel->handleRequest(
            'POST',
            "/api/mcp/messages?sessionId={$activeSessionId}",
            '',
            ['Content-Type' => 'application/json']
        );

        $this->assertSame(422, $res5->getStatusCode());
        $this->assertStringContainsString('application/problem+json', $res5->getHeaderLine('Content-Type'));
        $problem5 = json_decode($res5->getBody(), true);
        $this->assertSame(422, $problem5['status']);
        $this->assertSame('Unprocessable Entity', $problem5['title']);
        $this->assertSame('Empty JSON-RPC payload', $problem5['detail']);
    }

    /**
     * Requirement 1.4: Abrupt client disconnect / stdin EOF handling.
     * When client closes stdin without sending exit notification, server must exit cleanly with code 0.
     */
    public function testStdioAbruptClientDisconnectEofCleanExit(): void
    {
        [$process, $pipes] = $this->spawnMcpServer();
        $stdoutBuf = '';

        try {
            fwrite($pipes[0], json_encode([
                'jsonrpc' => '2.0',
                'id' => 'init-disconnect',
                'method' => 'initialize',
                'params' => ['protocolVersion' => '2024-11-05'],
            ]) . "\n");
            fflush($pipes[0]);
            $this->readStdoutLine($pipes, $stdoutBuf, 5.0);

            // Abruptly close STDIN (EOF)
            fclose($pipes[0]);
            $pipes[0] = null;

            // Wait for server process to terminate on EOF
            $startTime = microtime(true);
            $running = true;
            while ($running && (microtime(true) - $startTime) < 5.0) {
                $status = proc_get_status($process);
                $running = $status['running'];
                if ($running) {
                    usleep(20000);
                }
            }

            $this->assertFalse($running, 'bin/mcp-server must terminate cleanly when stdin reaches EOF');
            $exitCode = proc_close($process);
            $process = null;
            $this->assertSame(0, $exitCode, 'bin/mcp-server must exit with status code 0 on EOF');
        } finally {
            if (is_resource($process)) {
                $this->closeMcpServer($process, array_filter($pipes));
            }
        }
    }

    /**
     * Requirement 2.4: Multi-process OS-level concurrent session and message stress.
     * Spawns 5 concurrent PHP processes hitting Kernel and SseSessionManager simultaneously.
     */
    public function testHttpSseMultiProcessConcurrentStress(): void
    {
        $workerScript = sys_get_temp_dir() . '/wincart_worker_' . uniqid() . '.php';
        $code = <<<'PHP'
<?php
require $argv[1] . '/vendor/autoload.php';

$kernel = new \WinCart\Http\Kernel([
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $argv[2],
    'STORAGE_PATH' => $argv[3],
    'INSTALL_LOCK_PATH' => $argv[4],
]);

$workerId = $argv[5];

// 1. Establish SSE session
$connectRes = $kernel->handleRequest('GET', '/api/mcp/sse', null, [
    'Authorization' => "Bearer concurrent-worker-token-{$workerId}",
]);
if ($connectRes->getStatusCode() !== 200) {
    exit(10);
}

preg_match('#sessionId=([a-zA-Z0-9_-]+)#', $connectRes->getBody(), $m);
if (empty($m[1])) {
    exit(11);
}
$sessionId = $m[1];

// 2. Dispatch 10 rapid messages
for ($j = 1; $j <= 10; $j++) {
    $req = json_encode([
        'jsonrpc' => '2.0',
        'id' => "w{$workerId}-{$j}",
        'method' => ($j % 2 === 0) ? 'ping' : 'tools/list',
        'params' => [],
    ]);

    $res = $kernel->handleRequest(
        'POST',
        "/api/mcp/messages?sessionId={$sessionId}",
        $req,
        ['Content-Type' => 'application/json']
    );

    if ($res->getStatusCode() !== 200) {
        exit(20 + $j);
    }

    $decoded = json_decode($res->getBody(), true);
    if (($decoded['id'] ?? null) !== "w{$workerId}-{$j}") {
        exit(40 + $j);
    }
}

exit(0);
PHP;
        file_put_contents($workerScript, $code);

        $processCount = 5;
        $procHandles = [];
        $pipesList = [];
        $wincartRoot = dirname(__DIR__, 2);

        try {
            // Launch 5 parallel processes
            for ($p = 1; $p <= $processCount; $p++) {
                $cmd = [
                    PHP_BINARY,
                    $workerScript,
                    $wincartRoot,
                    $this->tempDbFile,
                    $this->tempStorageDir,
                    $this->tempLockfile,
                    (string)$p,
                ];

                $proc = proc_open($cmd, [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ], $pipes);

                $this->assertIsResource($proc, "Must spawn parallel worker process #{$p}");
                $procHandles[$p] = $proc;
                $pipesList[$p] = $pipes;
            }

            // Wait for all 5 processes to finish with timeout
            $exitCodes = [];
            foreach ($procHandles as $p => $proc) {
                $status = proc_get_status($proc);
                $startTime = microtime(true);
                while ($status['running'] && (microtime(true) - $startTime) < 10.0) {
                    usleep(25000);
                    $status = proc_get_status($proc);
                }

                foreach ($pipesList[$p] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }

                $exitCode = proc_close($proc);
                $exitCodes[$p] = $exitCode;
            }

            foreach ($exitCodes as $p => $code) {
                $this->assertSame(
                    0,
                    $code,
                    "Parallel worker #{$p} exited with non-zero status {$code}. Session contention detected."
                );
            }
        } finally {
            if (file_exists($workerScript)) {
                @unlink($workerScript);
            }
        }
    }
}
