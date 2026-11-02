<?php

declare(strict_types=1);

namespace WinCart\Mcp\Transports;

use WinCart\Http\ProblemDetails;
use WinCart\Mcp\McpServer;
use WinCart\Tests\E2E\Support\HttpResponse;

final class SseTransport
{
    public function __construct(
        private McpServer $mcpServer,
        private SseSessionManager $sessionManager
    ) {
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $queryParams
     */
    public function handleSseConnect(array $headers = [], array $queryParams = []): HttpResponse
    {
        $token = null;
        $auth = $headers['Authorization'] ?? $headers['authorization'] ?? null;
        if ($auth !== null && str_starts_with($auth, 'Bearer ')) {
            $token = trim(substr($auth, 7));
        }

        $sessionId = $this->sessionManager->createSession($token);

        $body = "event: endpoint\ndata: /api/mcp/messages?sessionId={$sessionId}\n\n";

        return new HttpResponse(200, $body, [
            'Content-Type' => ['text/event-stream; charset=UTF-8'],
            'Cache-Control' => ['no-cache'],
            'Connection' => ['keep-alive'],
            'X-Accel-Buffering' => ['no'],
        ]);
    }

    /**
     * @param array<string, string> $headers
     */
    public function handleMessagePost(string $sessionId, string $rawBody, array $headers = []): HttpResponse
    {
        if ($sessionId === '' || !$this->sessionManager->hasSession($sessionId)) {
            return ProblemDetails::create(404, 'Not Found', 'MCP session not found or expired');
        }

        $trimmed = trim($rawBody);
        if ($trimmed === '') {
            return ProblemDetails::create(422, 'Unprocessable Entity', 'Empty JSON-RPC payload');
        }

        $this->sessionManager->touchSession($sessionId);
        $session = $this->sessionManager->getSession($sessionId);
        $token = $session['token'] ?? null;

        $response = $this->mcpServer->handleMessage($trimmed, $token);

        if ($response === null) {
            return new HttpResponse(202, '{"status":"accepted"}', [
                'Content-Type' => ['application/json; charset=UTF-8'],
            ]);
        }

        return new HttpResponse(200, $response, [
            'Content-Type' => ['application/json; charset=UTF-8'],
        ]);
    }
}
