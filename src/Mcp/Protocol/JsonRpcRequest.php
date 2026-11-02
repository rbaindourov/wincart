<?php

declare(strict_types=1);

namespace WinCart\Mcp\Protocol;

use InvalidArgumentException;

final class JsonRpcRequest
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        public readonly string $jsonrpc,
        public readonly int|string|null $id,
        public readonly string $method,
        public readonly array $params = [],
        public readonly bool $isNotification = false
    ) {
    }

    /**
     * Parse raw JSON string into a JsonRpcRequest instance.
     *
     * @throws InvalidArgumentException
     */
    public static function fromJson(string $rawJson): self
    {
        $decoded = json_decode($rawJson, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Parse error: invalid JSON payload', -32700);
        }

        return self::fromArray($decoded);
    }

    /**
     * @param array<string, mixed> $data
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        $jsonrpc = (string)($data['jsonrpc'] ?? '');
        if ($jsonrpc !== '2.0') {
            throw new InvalidArgumentException('Invalid Request: "jsonrpc" must be "2.0"', -32600);
        }

        if (!isset($data['method']) || !is_string($data['method']) || trim($data['method']) === '') {
            throw new InvalidArgumentException('Invalid Request: "method" must be a non-empty string', -32600);
        }

        $method = trim($data['method']);
        $hasId = array_key_exists('id', $data);
        $id = $hasId ? $data['id'] : null;
        if ($id !== null && !is_int($id) && !is_string($id)) {
            throw new InvalidArgumentException('Invalid Request: "id" must be an integer, string, or null', -32600);
        }

        $params = isset($data['params']) && is_array($data['params']) ? $data['params'] : [];
        $isNotification = !$hasId;

        return new self(
            jsonrpc: $jsonrpc,
            id: $id,
            method: $method,
            params: $params,
            isNotification: $isNotification
        );
    }
}
