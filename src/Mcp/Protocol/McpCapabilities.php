<?php

declare(strict_types=1);

namespace WinCart\Mcp\Protocol;

final class McpCapabilities
{
    public const SUPPORTED_PROTOCOL_VERSION = '2024-11-05';
    public const SERVER_NAME = 'wincart-mcp';
    public const SERVER_VERSION = '1.0.0';

    /**
     * @return array<string, mixed>
     */
    public static function getServerInfo(): array
    {
        return [
            'name' => self::SERVER_NAME,
            'version' => self::SERVER_VERSION,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function getCapabilities(): array
    {
        return [
            'tools' => [
                'listChanged' => false,
            ],
            'resources' => [
                'subscribe' => false,
                'listChanged' => false,
            ],
            'prompts' => [
                'listChanged' => false,
            ],
        ];
    }
}
