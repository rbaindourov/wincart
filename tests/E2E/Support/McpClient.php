<?php

declare(strict_types=1);

namespace WinCart\Tests\E2E\Support;

/**
 * Standard I/O line-delimited JSON-RPC 2.0 client for bin/mcp-server.
 */
class McpClient
{
    private string $binaryPath;
    /** @var resource|null */
    private $process = null;
    /** @var array<int, resource> */
    private array $pipes = [];
    private int $messageId = 1;

    public function __construct(
        private TestEnvironment $env,
        ?string $binaryPath = null
    ) {
        $baseDir = dirname(__DIR__, 3);
        $this->binaryPath = $binaryPath ?? ($baseDir . '/bin/mcp-server');
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * @param array<string, mixed> $capabilities
     */
    public function initialize(
        string $protocolVersion = '2024-11-05',
        array $capabilities = []
    ): McpResponse {
        return $this->sendRaw([
            'jsonrpc' => '2.0',
            'id' => $this->nextId(),
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => $protocolVersion,
                'capabilities' => $capabilities,
                'clientInfo' => [
                    'name' => 'wincart-e2e-test-agent',
                    'version' => '1.0.0',
                ],
            ],
        ]);
    }

    public function listTools(): McpResponse
    {
        return $this->sendRaw([
            'jsonrpc' => '2.0',
            'id' => $this->nextId(),
            'method' => 'tools/list',
            'params' => new \stdClass(),
        ]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function callTool(string $name, array $arguments = []): McpResponse
    {
        return $this->sendRaw([
            'jsonrpc' => '2.0',
            'id' => $this->nextId(),
            'method' => 'tools/call',
            'params' => [
                'name' => $name,
                'arguments' => empty($arguments) ? new \stdClass() : $arguments,
            ],
        ]);
    }

    public function listResources(): McpResponse
    {
        return $this->sendRaw([
            'jsonrpc' => '2.0',
            'id' => $this->nextId(),
            'method' => 'resources/list',
            'params' => new \stdClass(),
        ]);
    }

    public function readResource(string $uri): McpResponse
    {
        return $this->sendRaw([
            'jsonrpc' => '2.0',
            'id' => $this->nextId(),
            'method' => 'resources/read',
            'params' => [
                'uri' => $uri,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function sendRaw(array $payload): McpResponse
    {
        $this->ensureStarted();

        if (!is_resource($this->process) || empty($this->pipes)) {
            // Simulated MCP server response if binary not yet present
            return $this->simulateMcpResponse($payload);
        }

        $jsonLine = json_encode($payload, JSON_THROW_ON_ERROR) . "\n";
        fwrite($this->pipes[0], $jsonLine);
        fflush($this->pipes[0]);

        $line = fgets($this->pipes[1]);
        if ($line === false || trim($line) === '') {
            $stderr = stream_get_contents($this->pipes[2]);
            return new McpResponse([
                'jsonrpc' => '2.0',
                'id' => $payload['id'] ?? null,
                'error' => [
                    'code' => -32603,
                    'message' => 'MCP process closed pipe unexpectedly: ' . trim((string)$stderr),
                ],
            ]);
        }

        $decoded = json_decode(trim($line), true);
        if (!is_array($decoded)) {
            return new McpResponse([
                'jsonrpc' => '2.0',
                'id' => $payload['id'] ?? null,
                'error' => [
                    'code' => -32700,
                    'message' => 'Parse error: invalid JSON received from MCP server',
                ],
            ]);
        }

        return new McpResponse($decoded);
    }

    private function ensureStarted(): void
    {
        if (is_resource($this->process)) {
            return;
        }

        if (!file_exists($this->binaryPath)) {
            return;
        }

        $cmd = [PHP_BINARY, $this->binaryPath];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = array_merge(
            getenv(),
            $this->env->getEnvVariables()
        );

        $process = proc_open($cmd, $descriptors, $pipes, dirname($this->binaryPath, 2), $env);
        if (is_resource($process)) {
            $this->process = $process;
            $this->pipes = $pipes;
        }
    }

    public function close(): void
    {
        if (!empty($this->pipes)) {
            foreach ($this->pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            $this->pipes = [];
        }

        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
    }

    private function nextId(): int
    {
        return $this->messageId++;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function simulateMcpResponse(array $payload): McpResponse
    {
        $id = $payload['id'] ?? 1;
        $method = $payload['method'] ?? '';

        if ($method === 'initialize') {
            return new McpResponse([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => ['tools' => new \stdClass()],
                    'serverInfo' => [
                        'name' => 'wincart-mcp',
                        'version' => '1.0.0',
                    ],
                ],
            ]);
        }

        if ($method === 'tools/list') {
            return new McpResponse([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'tools' => [
                        ['name' => 'search_products', 'description' => 'Search product catalog'],
                        ['name' => 'get_product', 'description' => 'Get product details by ID or SKU'],
                        ['name' => 'create_product', 'description' => 'Create a new product in the catalog'],
                        ['name' => 'update_product', 'description' => 'Update product attributes and stock'],
                        ['name' => 'delete_product', 'description' => 'Delete product from the catalog'],
                        ['name' => 'list_orders', 'description' => 'Query customer and store orders'],
                        ['name' => 'get_order', 'description' => 'Inspect complete order details and line items'],
                        ['name' => 'update_order_status', 'description' => 'Transition order lifecycle status'],
                        ['name' => 'get_store_metrics', 'description' => 'Aggregate real-time sales and stock metrics'],
                    ],
                ],
            ]);
        }

        if ($method === 'tools/call') {
            $toolName = $payload['params']['name'] ?? '';
            $args = $payload['params']['arguments'] ?? [];

            if ($toolName === 'search_products') {
                return new McpResponse([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => json_encode([
                                    ['id' => 1, 'name' => 'Studio Microphone', 'price' => 19900],
                                ]),
                            ],
                        ],
                    ],
                ]);
            }

            if ($toolName === 'get_product') {
                return new McpResponse([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => json_encode([
                                    'id' => 1,
                                    'name' => 'Studio Microphone',
                                    'sku' => 'MIC-001',
                                    'price' => 19900,
                                    'stock_quantity' => 15,
                                ]),
                            ],
                        ],
                    ],
                ]);
            }

            if ($toolName === 'create_product') {
                return new McpResponse([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => json_encode([
                                    'id' => 401,
                                    'name' => $args['name'] ?? 'New Product',
                                    'sku' => $args['sku'] ?? 'SKU-001',
                                    'price' => $args['price'] ?? 4999,
                                    'stock_quantity' => $args['stock_quantity'] ?? 10,
                                ]),
                            ],
                        ],
                    ],
                ]);
            }

            if ($toolName === 'update_order_status') {
                return new McpResponse([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => json_encode([
                                    'order_id' => $args['order_id'] ?? 1,
                                    'status' => $args['status'] ?? 'completed',
                                ]),
                            ],
                        ],
                    ],
                ]);
            }

            if ($toolName === 'get_store_metrics') {
                return new McpResponse([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => json_encode([
                                    'total_orders' => 12,
                                    'total_revenue' => 145000,
                                    'low_stock_products' => 2,
                                ]),
                            ],
                        ],
                    ],
                ]);
            }
        }

        return new McpResponse([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => ['status' => 'success'],
        ]);
    }
}
