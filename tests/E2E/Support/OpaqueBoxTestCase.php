<?php

declare(strict_types=1);

namespace WinCart\Tests\E2E\Support;

use PHPUnit\Framework\TestCase;

/**
 * Base test case for all winCart opaque-box end-to-end tests (Tiers 1-4).
 * Provides sandboxed test environments, isolated transports, and dual-track requirement guards.
 */
abstract class OpaqueBoxTestCase extends TestCase
{
    protected TestEnvironment $env;
    protected HttpClient $http;
    protected CliRunner $cli;
    protected McpClient $mcp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->env = new TestEnvironment();
        $this->http = new HttpClient($this->env);
        $this->cli = new CliRunner($this->env);
        $this->mcp = new McpClient($this->env);
    }

    protected function tearDown(): void
    {
        if (isset($this->mcp)) {
            $this->mcp->close();
        }

        if (isset($this->env)) {
            $this->env->cleanUp();
        }

        parent::tearDown();
    }

    /**
     * Guard declaring requirement for the HTTP application kernel.
     * In Dual-Track development prior to Milestone 3 delivery, marks test incomplete.
     */
    protected function requireHttp(): void
    {
        if (!$this->http->isAvailable()) {
            $this->markTestIncomplete(
                'Awaiting Track A HTTP delivery: WinCart\\Http\\Kernel is not yet implemented.'
            );
        }
    }

    /**
     * Guard declaring requirement for the CLI management binary.
     * In Dual-Track development prior to Milestone 5 delivery, marks test incomplete.
     */
    protected function requireCli(): void
    {
        if (!$this->cli->isAvailable()) {
            $this->markTestIncomplete(
                'Awaiting Track A CLI delivery: bin/wincart is not yet implemented.'
            );
        }
    }

    /**
     * Guard declaring requirement for the stdio MCP server binary.
     * In Dual-Track development prior to Milestone 4 delivery, marks test incomplete.
     */
    protected function requireMcp(): void
    {
        if (!$this->mcp->isAvailable()) {
            $this->markTestIncomplete(
                'Awaiting Track A MCP delivery: bin/mcp-server is not yet implemented.'
            );
        }
    }

    protected function assertProblemDetails(
        HttpResponse $response,
        int $expectedStatus,
        ?string $expectedTitle = null
    ): void {
        $this->assertSame($expectedStatus, $response->getStatusCode());
        $contentType = $response->getHeaderLine('Content-Type');
        $this->assertTrue(
            str_contains($contentType, 'application/problem+json')
            || str_contains($contentType, 'application/json'),
            "Expected Content-Type application/problem+json, got: {$contentType}"
        );

        $json = $response->getJson();
        $this->assertIsArray($json, 'Problem details body must be valid JSON');

        // RFC 7807 Canonical Member Verification
        $this->assertArrayHasKey('type', $json, 'RFC 7807 problem details must contain "type" member');
        $this->assertArrayHasKey('title', $json, 'RFC 7807 problem details must contain "title" member');
        $this->assertArrayHasKey('status', $json, 'RFC 7807 problem details must contain "status" member');
        $this->assertArrayHasKey('detail', $json, 'RFC 7807 problem details must contain "detail" member');

        $this->assertSame($expectedStatus, (int)$json['status']);

        if ($expectedTitle !== null) {
            $this->assertStringContainsStringIgnoringCase($expectedTitle, (string)$json['title']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function assertJsonResponse(HttpResponse $response, int $expectedStatus): array
    {
        $this->assertSame($expectedStatus, $response->getStatusCode());
        $contentType = $response->getHeaderLine('Content-Type');
        $this->assertTrue(
            str_contains($contentType, 'application/json'),
            "Expected Content-Type application/json, got: {$contentType}"
        );

        $json = $response->getJson();
        $this->assertIsArray($json);
        return $json;
    }

    protected function assertJsonRpcSuccess(McpResponse $response): void
    {
        $this->assertTrue(
            $response->isSuccess(),
            'Expected JSON-RPC success, but received error: ' . json_encode($response->getError())
        );
        $this->assertFalse($response->isError());
    }

    protected function assertJsonRpcError(McpResponse $response, ?int $expectedCode = null): void
    {
        $this->assertTrue($response->isError(), 'Expected JSON-RPC error, but received success');
        if ($expectedCode !== null) {
            $error = $response->getError();
            $this->assertNotNull($error);
            $this->assertSame($expectedCode, $error['code'] ?? null);
        }
    }
}
