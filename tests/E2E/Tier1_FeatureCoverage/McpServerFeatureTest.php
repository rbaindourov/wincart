<?php

declare(strict_types=1);

namespace WinCart\Tests\E2E\Tier1_FeatureCoverage;

use WinCart\Tests\E2E\Support\OpaqueBoxTestCase;

/**
 * Tier 1: Feature Coverage — Native Model Context Protocol (MCP) Server.
 * Verifies R3 and Features #14, #16, #17 from PROJECT.md.
 */
class McpServerFeatureTest extends OpaqueBoxTestCase
{
    /**
     * Test 1: stdio line-delimited JSON-RPC 2.0 initialize handshake conforming to 2024-11-05.
     */
    public function testMcpStdioInitializeHandshake(): void
    {
        $response = $this->mcp->initialize('2024-11-05');

        $this->assertJsonRpcSuccess($response);
        $result = $response->getResult();
        $this->assertSame('2024-11-05', $result['protocolVersion'] ?? null);
        $this->assertArrayHasKey('serverInfo', $result);
        $this->assertSame('wincart-mcp', $result['serverInfo']['name'] ?? null);
    }

    /**
     * Test 2: MCP tools/list enumeration of all 9 core store management tools.
     */
    public function testMcpToolsListEnumeration(): void
    {
        $this->mcp->initialize('2024-11-05');
        $response = $this->mcp->listTools();

        $this->assertJsonRpcSuccess($response);
        $result = $response->getResult();
        $this->assertArrayHasKey('tools', $result);
        $this->assertIsArray($result['tools']);
        $this->assertGreaterThanOrEqual(9, count($result['tools']));

        $toolNames = array_column($result['tools'], 'name');
        $expectedTools = [
            'search_products',
            'get_product',
            'create_product',
            'update_product',
            'delete_product',
            'list_orders',
            'get_order',
            'update_order_status',
            'get_store_metrics',
        ];

        foreach ($expectedTools as $expected) {
            $this->assertContains($expected, $toolNames, "Missing required MCP tool: {$expected}");
        }
    }

    /**
     * Test 3: Tool execution — search_products.
     */
    public function testMcpToolSearchProducts(): void
    {
        $this->mcp->initialize('2024-11-05');
        $response = $this->mcp->callTool('search_products', ['query' => 'Microphone']);

        $this->assertJsonRpcSuccess($response);
        $text = $response->getTextContent();
        $this->assertStringContainsString('Microphone', $text);
    }

    /**
     * Test 4: Tool execution — get_product by ID.
     */
    public function testMcpToolGetProduct(): void
    {
        $this->mcp->initialize('2024-11-05');
        $response = $this->mcp->callTool('get_product', ['id' => 1]);

        $this->assertJsonRpcSuccess($response);
        $text = $response->getTextContent();
        $this->assertStringContainsString('id', $text);
        $this->assertStringContainsString('sku', $text);
    }

    /**
     * Test 5: Tool execution — create_product.
     */
    public function testMcpToolCreateProduct(): void
    {
        $this->mcp->initialize('2024-11-05');
        $response = $this->mcp->callTool('create_product', [
            'name' => 'Agent Sensor',
            'sku' => 'SENS-01',
            'price' => 4999,
            'stock_quantity' => 20,
        ]);

        $this->assertJsonRpcSuccess($response);
        $text = $response->getTextContent();
        $this->assertStringContainsString('SENS-01', $text);
    }

    /**
     * Test 6: Tool execution — update_order_status.
     */
    public function testMcpToolUpdateOrderStatus(): void
    {
        $this->mcp->initialize('2024-11-05');
        $response = $this->mcp->callTool('update_order_status', [
            'order_id' => 1,
            'status' => 'completed',
            'note' => 'Fulfilled by AI agent',
        ]);

        $this->assertJsonRpcSuccess($response);
        $text = $response->getTextContent();
        $this->assertStringContainsString('completed', $text);
    }

    /**
     * Test 7: Tool execution — get_store_metrics.
     */
    public function testMcpToolGetStoreMetrics(): void
    {
        $this->mcp->initialize('2024-11-05');
        $response = $this->mcp->callTool('get_store_metrics', []);

        $this->assertJsonRpcSuccess($response);
        $text = $response->getTextContent();
        $this->assertStringContainsString('total_orders', $text);
    }
}
