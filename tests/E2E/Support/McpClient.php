<?php

declare(strict_types=1);

namespace WinCart\Tests\E2E\Support;

/**
 * Standard I/O line-delimited JSON-RPC 2.0 client for bin/mcp-server.
 * Uses non-blocking stream_select to continuously drain STDERR and prevent pipe deadlocks.
 * Strictly communicates with the actual binary with zero canned facade simulations.
 */
class McpClient
{
    private string $binaryPath;
    /** @var resource|null */
    private $process = null;
    /** @var array<int, resource> */
    private array $pipes = [];
    private int $messageId = 1;
    private string $stdoutBuffer = '';
    private string $stderrBuffer = '';

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

    public function isAvailable(): bool
    {
        return file_exists($this->binaryPath);
    }

    public function getBinaryPath(): string
    {
        return $this->binaryPath;
    }

    public function getStderr(): string
    {
        return $this->stderrBuffer;
    }

    public function clearStderr(): void
    {
        $this->stderrBuffer = '';
    }

    /**
     * @param array<string, mixed> $capabilities
     */
    public function initialize(
        string $protocolVersion = '2024-11-05',
        array $capabilities = [],
        float $timeout = 10.0
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
        ], $timeout);
    }

    public function listTools(float $timeout = 10.0): McpResponse
    {
        return $this->sendRaw([
            'jsonrpc' => '2.0',
            'id' => $this->nextId(),
            'method' => 'tools/list',
            'params' => new \stdClass(),
        ], $timeout);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function callTool(string $name, array $arguments = [], float $timeout = 10.0): McpResponse
    {
        return $this->sendRaw([
            'jsonrpc' => '2.0',
            'id' => $this->nextId(),
            'method' => 'tools/call',
            'params' => [
                'name' => $name,
                'arguments' => empty($arguments) ? new \stdClass() : $arguments,
            ],
        ], $timeout);
    }

    public function listResources(float $timeout = 10.0): McpResponse
    {
        return $this->sendRaw([
            'jsonrpc' => '2.0',
            'id' => $this->nextId(),
            'method' => 'resources/list',
            'params' => new \stdClass(),
        ], $timeout);
    }

    public function readResource(string $uri, float $timeout = 10.0): McpResponse
    {
        return $this->sendRaw([
            'jsonrpc' => '2.0',
            'id' => $this->nextId(),
            'method' => 'resources/read',
            'params' => [
                'uri' => $uri,
            ],
        ], $timeout);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function sendRaw(array $payload, float $timeout = 10.0): McpResponse
    {
        $this->ensureStarted();

        if (!is_resource($this->process) || empty($this->pipes)) {
            throw new \RuntimeException('MCP server process is not running.');
        }

        // Drain any pending stderr prior to writing new request
        $this->drainStderr();

        $jsonLine = json_encode($payload, JSON_THROW_ON_ERROR) . "\n";
        $written = fwrite($this->pipes[0], $jsonLine);
        if ($written === false) {
            $this->drainStderr();
            return new McpResponse([
                'jsonrpc' => '2.0',
                'id' => $payload['id'] ?? null,
                'error' => [
                    'code' => -32603,
                    'message' => 'Failed to write to MCP STDIN pipe. Stderr: ' . trim($this->stderrBuffer),
                ],
            ]);
        }
        fflush($this->pipes[0]);

        $startTime = microtime(true);

        while (!str_contains($this->stdoutBuffer, "\n")) {
            $this->drainStderr();

            $elapsed = microtime(true) - $startTime;
            if ($elapsed >= $timeout) {
                $this->drainStderr();
                return new McpResponse([
                    'jsonrpc' => '2.0',
                    'id' => $payload['id'] ?? null,
                    'error' => [
                        'code' => -32000,
                        'message' => "MCP request timed out after {$timeout}s. Stderr: " . trim($this->stderrBuffer),
                    ],
                ]);
            }

            $r = [$this->pipes[1], $this->pipes[2]];
            $w = null;
            $e = null;

            $remaining = max(0.001, $timeout - $elapsed);
            $sec = (int)floor($remaining);
            $usec = (int)(($remaining - $sec) * 1_000_000);

            $numChanged = stream_select($r, $w, $e, $sec, $usec);
            if ($numChanged === false) {
                break;
            }

            if (in_array($this->pipes[2], $r, true)) {
                $this->drainStderr();
            }

            if (in_array($this->pipes[1], $r, true)) {
                $chunk = fread($this->pipes[1], 8192);
                if ($chunk !== false && $chunk !== '') {
                    $this->stdoutBuffer .= $chunk;
                } elseif (feof($this->pipes[1])) {
                    $this->drainStderr();
                    return new McpResponse([
                        'jsonrpc' => '2.0',
                        'id' => $payload['id'] ?? null,
                        'error' => [
                            'code' => -32603,
                            'message' => 'MCP process closed pipe unexpectedly: ' . trim($this->stderrBuffer),
                        ],
                    ]);
                }
            }
        }

        $newlinePos = strpos($this->stdoutBuffer, "\n");
        if ($newlinePos === false) {
            $this->drainStderr();
            return new McpResponse([
                'jsonrpc' => '2.0',
                'id' => $payload['id'] ?? null,
                'error' => [
                    'code' => -32603,
                    'message' => 'MCP process closed without completing response line: ' . trim($this->stderrBuffer),
                ],
            ]);
        }

        $line = substr($this->stdoutBuffer, 0, $newlinePos);
        $this->stdoutBuffer = substr($this->stdoutBuffer, $newlinePos + 1);

        $decoded = json_decode(trim($line), true);
        if (!is_array($decoded)) {
            return new McpResponse([
                'jsonrpc' => '2.0',
                'id' => $payload['id'] ?? null,
                'error' => [
                    'code' => -32700,
                    'message' => 'Parse error: invalid JSON received from MCP server. Line was: ' . $line,
                ],
            ]);
        }

        return new McpResponse($decoded);
    }

    private function drainStderr(): void
    {
        if (isset($this->pipes[2]) && is_resource($this->pipes[2])) {
            while (($chunk = fread($this->pipes[2], 8192)) !== false && $chunk !== '') {
                $this->stderrBuffer .= $chunk;
            }
        }
    }

    private function ensureStarted(): void
    {
        if (is_resource($this->process)) {
            return;
        }

        if (!file_exists($this->binaryPath)) {
            throw new \RuntimeException(
                "MCP server binary not found at [{$this->binaryPath}]. Awaiting Track A Milestone 4 delivery."
            );
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
        if (!is_resource($process)) {
            throw new \RuntimeException("Failed to spawn MCP server process: " . implode(' ', $cmd));
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $this->process = $process;
        $this->pipes = $pipes;
        $this->stdoutBuffer = '';
        $this->stderrBuffer = '';
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
            $status = proc_get_status($this->process);
            if ($status['running']) {
                proc_terminate($this->process);
            }
            proc_close($this->process);
            $this->process = null;
        }

        $this->stdoutBuffer = '';
    }

    private function nextId(): int
    {
        return $this->messageId++;
    }
}
