<?php

declare(strict_types=1);

namespace WinCart\Mcp\Protocol;

final class JsonRpcResponse
{
    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;

    /**
     * @param int|string|null $id
     * @param array<string, mixed> $result
     */
    public static function success(int|string|null $id, array $result): string
    {
        return (string)json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param int|string|null $id
     */
    public static function error(
        int|string|null $id,
        int $code,
        string $message,
        mixed $data = null
    ): string {
        $error = [
            'code' => $code,
            'message' => $message,
        ];
        if ($data !== null) {
            $error['data'] = $data;
        }

        return (string)json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => $error,
        ], JSON_UNESCAPED_SLASHES);
    }
}
