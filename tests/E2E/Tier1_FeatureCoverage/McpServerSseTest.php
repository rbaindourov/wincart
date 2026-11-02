<?php

declare(strict_types=1);

namespace WinCart\Tests\E2E\Tier1_FeatureCoverage;

use WinCart\Tests\E2E\Support\OpaqueBoxTestCase;

/**
 * Tier 1: Feature Coverage — MCP Server SSE Transport.
 * Verifies GET /api/mcp/sse handshake and POST /api/mcp/messages RPC exchange.
 */
class McpServerSseTest extends OpaqueBoxTestCase
{
    public function testMcpServerSseHandshakeAndMessageExchange(): void
    {
        $this->requireHttp();
        $this->env->createLockfile();

        // 1. Initial SSE connection handshake
        $sseRes = $this->http->get('/api/mcp/sse');
        $this->assertSame(200, $sseRes->getStatusCode());
        $this->assertStringContainsString('text/event-stream', $sseRes->getHeaderLine('Content-Type'));

        $body = $sseRes->getBody();
        $this->assertStringContainsString('event: endpoint', $body);
        $this->assertStringContainsString('/api/mcp/messages?sessionId=', $body);

        // Extract sessionId from SSE response body
        preg_match('#sessionId=([a-zA-Z0-9_-]+)#', $body, $matches);
        $this->assertNotEmpty($matches[1], 'Session ID should be present in endpoint event');
        $sessionId = $matches[1];

        // 2. Initialize MCP session via POST /api/mcp/messages
        $initRequest = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2024-11-05',
                'clientInfo' => ['name' => 'test-agent', 'version' => '1.0.0'],
            ],
        ];

        $initRes = $this->http->post(
            "/api/mcp/messages?sessionId={$sessionId}",
            $initRequest
        );

        $this->assertSame(200, $initRes->getStatusCode());
        $initJson = $this->assertJsonResponse($initRes, 200);
        $this->assertSame('2.0', $initJson['jsonrpc']);
        $this->assertSame(1, $initJson['id']);
        $this->assertSame('2024-11-05', $initJson['result']['protocolVersion']);
        $this->assertSame('wincart-mcp', $initJson['result']['serverInfo']['name']);

        // 3. List tools via POST /api/mcp/messages
        $toolsRequest = [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => [],
        ];

        $toolsRes = $this->http->post(
            "/api/mcp/messages?sessionId={$sessionId}",
            $toolsRequest
        );

        $this->assertSame(200, $toolsRes->getStatusCode());
        $toolsJson = $this->assertJsonResponse($toolsRes, 200);
        $this->assertArrayHasKey('result', $toolsJson);
        $this->assertArrayHasKey('tools', $toolsJson['result']);
        $this->assertGreaterThanOrEqual(9, count($toolsJson['result']['tools']));
    }
}
