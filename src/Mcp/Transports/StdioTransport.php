<?php

declare(strict_types=1);

namespace WinCart\Mcp\Transports;

use Throwable;
use WinCart\Mcp\McpServer;
use WinCart\Mcp\Protocol\JsonRpcResponse;

final class StdioTransport
{
    /** @var resource|null */
    private $stdin = null;
    /** @var resource|null */
    private $stdout = null;
    /** @var resource|null */
    private $stderr = null;

    public function __construct(
        private McpServer $mcpServer,
        $stdin = null,
        $stdout = null,
        $stderr = null
    ) {
        $this->stdin = $stdin;
        $this->stdout = $stdout;
        $this->stderr = $stderr;
    }

    public function run(): int
    {
        $in = $this->stdin ?? fopen('php://stdin', 'r');
        $out = $this->stdout ?? fopen('php://stdout', 'w');
        $err = $this->stderr ?? fopen('php://stderr', 'w');

        if (!is_resource($in) || !is_resource($out)) {
            return 1;
        }

        while (!feof($in)) {
            $line = fgets($in);
            if ($line === false) {
                break;
            }

            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            try {
                $response = $this->mcpServer->handleMessage($trimmed);
                if ($response !== null) {
                    fwrite($out, $response . "\n");
                    fflush($out);
                }
            } catch (Throwable $e) {
                if (is_resource($err)) {
                    fwrite($err, "[wincart-mcp error] " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
                    fflush($err);
                }
                $errResponse = JsonRpcResponse::error(
                    null,
                    JsonRpcResponse::INTERNAL_ERROR,
                    'Fatal server error: ' . $e->getMessage()
                );
                fwrite($out, $errResponse . "\n");
                fflush($out);
            }
        }

        return 0;
    }
}
