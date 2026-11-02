<?php

declare(strict_types=1);

namespace WinCart\Mcp;

use InvalidArgumentException;
use PDO;
use Throwable;
use WinCart\Admin\AdminMetricAggregator;
use WinCart\Catalog\Repositories\PdoProductRepository;
use WinCart\Checkout\PdoOrderRepository;
use WinCart\Checkout\OrderStateMachine;
use WinCart\Mcp\Protocol\JsonRpcRequest;
use WinCart\Mcp\Protocol\JsonRpcResponse;
use WinCart\Mcp\Protocol\McpCapabilities;
use WinCart\Mcp\Prompts\CustomerSupportInquiryPrompt;
use WinCart\Mcp\Prompts\DailyStoreBriefingPrompt;
use WinCart\Mcp\Prompts\PromptRegistry;
use WinCart\Mcp\Resources\InventoryAlertsResource;
use WinCart\Mcp\Resources\ResourceRegistry;
use WinCart\Mcp\Resources\StoreMetricsResource;
use WinCart\Mcp\Tools\CreateProductTool;
use WinCart\Mcp\Tools\DeleteProductTool;
use WinCart\Mcp\Tools\GetOrderTool;
use WinCart\Mcp\Tools\GetProductTool;
use WinCart\Mcp\Tools\GetStoreMetricsTool;
use WinCart\Mcp\Tools\ListOrdersTool;
use WinCart\Mcp\Tools\SearchProductsTool;
use WinCart\Mcp\Tools\ToolRegistry;
use WinCart\Mcp\Tools\UpdateOrderStatusTool;
use WinCart\Mcp\Tools\UpdateProductTool;

final class McpServer
{
    private ToolRegistry $tools;
    private ResourceRegistry $resources;
    private PromptRegistry $prompts;

    public function __construct(
        private PDO $pdo,
        private ?string $apiToken = null
    ) {
        $this->tools = new ToolRegistry();
        $this->resources = new ResourceRegistry();
        $this->prompts = new PromptRegistry();

        $this->registerComponents();
    }

    private function registerComponents(): void
    {
        $productRepo = new PdoProductRepository($this->pdo);
        $orderRepo = new PdoOrderRepository($this->pdo);
        $orderStateMachine = new OrderStateMachine($this->pdo, $orderRepo);
        $metricAggregator = new AdminMetricAggregator($this->pdo);

        // 9 Core Tools
        $this->tools->register(new SearchProductsTool($productRepo));
        $this->tools->register(new GetProductTool($productRepo));
        $this->tools->register(new CreateProductTool($this->pdo, $productRepo));
        $this->tools->register(new UpdateProductTool($productRepo));
        $this->tools->register(new DeleteProductTool($productRepo));
        $this->tools->register(new ListOrdersTool($orderRepo));
        $this->tools->register(new GetOrderTool($orderRepo));
        $this->tools->register(new UpdateOrderStatusTool($this->pdo, $orderStateMachine, $orderRepo));
        $this->tools->register(new GetStoreMetricsTool($metricAggregator));

        // Resources
        $this->resources->register(new StoreMetricsResource($this->pdo, $metricAggregator));
        $this->resources->register(new InventoryAlertsResource($this->pdo));

        // Prompts
        $this->prompts->register(new DailyStoreBriefingPrompt($this->pdo, $metricAggregator));
        $this->prompts->register(new CustomerSupportInquiryPrompt($orderRepo));
    }

    public function getTools(): ToolRegistry
    {
        return $this->tools;
    }

    public function getResources(): ResourceRegistry
    {
        return $this->resources;
    }

    public function getPrompts(): PromptRegistry
    {
        return $this->prompts;
    }

    /**
     * Handle incoming JSON-RPC 2.0 message and return JSON response string (or null for notifications).
     */
    public function handleMessage(string $rawMessage, ?string $token = null): ?string
    {
        try {
            $request = JsonRpcRequest::fromJson($rawMessage);
        } catch (InvalidArgumentException $e) {
            $code = $e->getCode() !== 0 ? $e->getCode() : JsonRpcResponse::PARSE_ERROR;
            return JsonRpcResponse::error(null, $code, $e->getMessage());
        } catch (Throwable $e) {
            return JsonRpcResponse::error(null, JsonRpcResponse::PARSE_ERROR, 'Failed to parse JSON-RPC request');
        }

        if ($request->isNotification) {
            return null;
        }

        $id = $request->id;
        $method = $request->method;
        $params = $request->params;

        try {
            return match ($method) {
                'initialize' => $this->handleInitialize($id, $params),
                'ping' => JsonRpcResponse::success($id, []),
                'tools/list' => JsonRpcResponse::success($id, ['tools' => $this->tools->listTools()]),
                'tools/call' => $this->handleToolCall($id, $params),
                'resources/list' => JsonRpcResponse::success($id, ['resources' => $this->resources->listResources()]),
                'resources/read' => $this->handleResourceRead($id, $params),
                'prompts/list' => JsonRpcResponse::success($id, ['prompts' => $this->prompts->listPrompts()]),
                'prompts/get' => $this->handlePromptGet($id, $params),
                default => JsonRpcResponse::error(
                    $id,
                    JsonRpcResponse::METHOD_NOT_FOUND,
                    "Method '{$method}' not found"
                ),
            };
        } catch (Throwable $e) {
            return JsonRpcResponse::error($id, JsonRpcResponse::INTERNAL_ERROR, 'Internal error: ' . $e->getMessage());
        }
    }

    /**
     * @param int|string|null $id
     * @param array<string, mixed> $params
     */
    private function handleInitialize(int|string|null $id, array $params): string
    {
        $clientVersion = (string)($params['protocolVersion'] ?? McpCapabilities::SUPPORTED_PROTOCOL_VERSION);
        $protocolVersion = $clientVersion !== '' ? $clientVersion : McpCapabilities::SUPPORTED_PROTOCOL_VERSION;

        return JsonRpcResponse::success($id, [
            'protocolVersion' => $protocolVersion,
            'serverInfo' => McpCapabilities::getServerInfo(),
            'capabilities' => McpCapabilities::getCapabilities(),
        ]);
    }

    /**
     * @param int|string|null $id
     * @param array<string, mixed> $params
     */
    private function handleToolCall(int|string|null $id, array $params): string
    {
        $name = isset($params['name']) ? (string)$params['name'] : '';
        if ($name === '') {
            return JsonRpcResponse::error($id, JsonRpcResponse::INVALID_PARAMS, 'Missing required tool "name"');
        }

        $arguments = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : [];
        $result = $this->tools->call($name, $arguments);

        return JsonRpcResponse::success($id, $result->toResponseArray());
    }

    /**
     * @param int|string|null $id
     * @param array<string, mixed> $params
     */
    private function handleResourceRead(int|string|null $id, array $params): string
    {
        $uri = isset($params['uri']) ? (string)$params['uri'] : '';
        if ($uri === '') {
            return JsonRpcResponse::error($id, JsonRpcResponse::INVALID_PARAMS, 'Missing required resource "uri"');
        }

        try {
            $content = $this->resources->read($uri);
            return JsonRpcResponse::success($id, [
                'contents' => [$content->toResponseArray()],
            ]);
        } catch (InvalidArgumentException $e) {
            return JsonRpcResponse::error($id, JsonRpcResponse::INVALID_PARAMS, $e->getMessage());
        }
    }

    /**
     * @param int|string|null $id
     * @param array<string, mixed> $params
     */
    private function handlePromptGet(int|string|null $id, array $params): string
    {
        $name = isset($params['name']) ? (string)$params['name'] : '';
        if ($name === '') {
            return JsonRpcResponse::error($id, JsonRpcResponse::INVALID_PARAMS, 'Missing required prompt "name"');
        }

        $arguments = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : [];
        try {
            $result = $this->prompts->getPrompt($name, $arguments);
            return JsonRpcResponse::success($id, $result->toResponseArray());
        } catch (InvalidArgumentException $e) {
            return JsonRpcResponse::error($id, JsonRpcResponse::INVALID_PARAMS, $e->getMessage());
        }
    }
}
